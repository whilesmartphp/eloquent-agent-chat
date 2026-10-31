<?php

namespace Whilesmart\AgentChat\Runs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;
use Whilesmart\AgentChat\Blocks\BlockBuilder;
use Whilesmart\AgentChat\Blocks\BlockCollector;
use Whilesmart\AgentChat\Contracts\MediaResolver;
use Whilesmart\AgentChat\Contracts\ProviderPlan;
use Whilesmart\AgentChat\Contracts\RetryPolicy;
use Whilesmart\AgentChat\Contracts\RunInputBuilder;
use Whilesmart\AgentChat\Enums\AgentRunAttemptStatus;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\AgentChat\Events\AgentRunAttemptFailed;
use Whilesmart\AgentChat\Events\AgentRunCancelled;
use Whilesmart\AgentChat\Events\AgentRunCompleted;
use Whilesmart\AgentChat\Events\AgentRunFailed;
use Whilesmart\AgentChat\Events\AgentRunProgressed;
use Whilesmart\AgentChat\Events\AgentRunStarted;
use Whilesmart\AgentChat\Events\AgentRunUpdated;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;
use Whilesmart\Agents\AgentManager;
use Whilesmart\Agents\Enums\StreamEventType;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\Conversations\Enums\MessageDirection;
use Whilesmart\Conversations\Enums\MessageType;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

/**
 * Executes a claimed run: tries each provider in the plan as an attempt,
 * records progress as tools run, and posts the reply to the conversation as
 * a message whose metadata holds the blocks.
 */
class RunExecutor
{
    public function __construct(
        private RunInputBuilder $input,
        private MediaResolver $media,
        private ProviderPlan $plan,
        private RetryPolicy $retry,
        private BlockBuilder $blocks,
        private ProgressLabels $labels,
        private RunDispatcher $dispatcher,
    ) {}

    /**
     * Take a run for this worker. Only a queued run, or a running run whose
     * worker has outlived the job timeout, can be claimed, and the claim is a
     * single conditional update so two workers never run the same prompt.
     */
    public function claim(int|string $runId): ?AgentRun
    {
        $model = config('agent-chat.model', AgentRun::class);
        $run = $model::query()->find($runId);

        if ($run === null || $this->dispatcher->mustWait($run)) {
            return null;
        }

        $claimed = $model::query()
            ->whereKey($runId)
            ->where(fn ($query) => $query
                ->where('status', AgentRunStatus::Queued->value)
                ->orWhere(fn ($query) => $query
                    ->where('status', AgentRunStatus::Running->value)
                    ->where('started_at', '<', $this->staleBefore())))
            ->update(['status' => AgentRunStatus::Running->value, 'started_at' => now(), 'error' => null]);

        if ($claimed !== 1) {
            return null;
        }

        $this->abandonRunningAttempts($runId);

        return $model::query()->find($runId);
    }

    /**
     * Execute a claimed run. Returns false when every provider failed but the
     * queue may try again: the run goes back to queued instead of failing.
     */
    public function execute(AgentRun $run, bool $finalTry = true): bool
    {
        if ($run->reply_message_id !== null) {
            return true;
        }

        if (! $this->requesterMayAct($run)) {
            $this->fail($run, 'The person who asked no longer has access to this conversation.');

            return true;
        }

        AgentRunStarted::dispatch($run);
        $this->broadcast($run, 'started');

        $input = $this->input->build($run);
        $media = $this->media->resolve($run);
        $error = 'The agent could not finish this run.';

        foreach ($this->plan->candidates($run) ?: [[]] as $candidate) {
            if (! $this->stillOurs($run)) {
                return true;
            }

            $attempt = $this->openAttempt($run, $candidate);
            $collector = new BlockCollector;
            $progress = $this->progressFor($run);
            app()->instance(BlockCollector::class, $collector);
            app()->instance(RunProgress::class, $progress);

            try {
                $result = $this->agents()->stream(
                    $run->harness,
                    $input,
                    $this->context($run, $attempt),
                    fn (AgentStreamEvent $event) => $this->track($progress, $event),
                    $media,
                    array_filter($candidate, fn ($value) => $value !== null),
                );
            } catch (Throwable $e) {
                report($e);
                $result = AgentResult::failed('The agent stopped unexpectedly.');
            } finally {
                app()->instance(BlockCollector::class, new BlockCollector);
                app()->instance(RunProgress::class, new RunProgress);
            }

            if ($result->ok && $result->finishReason !== 'error') {
                $this->publish($run, $attempt, $result, $collector);

                return true;
            }

            $error = $result->ok ? 'The answer was cut off before it finished.' : ($result->error ?? $error);
            $retryable = $this->retry->retryable($run, $attempt, $result);
            $this->closeAttempt($attempt, AgentRunAttemptStatus::Failed, $result, $error, $retryable);
            AgentRunAttemptFailed::dispatch($run, $attempt->refresh());

            if (! $retryable) {
                $this->fail($run, $error);

                return true;
            }
        }

        if (! $finalTry && $this->stillOurs($run)) {
            $run->forceFill(['status' => AgentRunStatus::Queued, 'started_at' => null, 'progress' => null, 'error' => $error])->save();

            return false;
        }

        $this->fail($run, $error);

        return true;
    }

    /**
     * Settle a run as failed, unless it already settled.
     */
    public function fail(AgentRun $run, string $error): void
    {
        $model = config('agent-chat.model', AgentRun::class);

        $failed = $model::query()
            ->whereKey($run->getKey())
            ->whereIn('status', AgentRunStatus::unsettledValues())
            ->update(['status' => AgentRunStatus::Failed->value, 'error' => $error, 'completed_at' => now()]);

        $run->refresh();

        if ($failed !== 1) {
            return;
        }

        AgentRunFailed::dispatch($run);
        $this->broadcast($run, 'settled');
        $this->dispatcher->next($run->conversation_id);
    }

    /**
     * Settle a run as cancelled. A worker still running it loses its claim and
     * its answer is discarded.
     */
    public function cancel(AgentRun $run): bool
    {
        $model = config('agent-chat.model', AgentRun::class);

        $cancelled = $model::query()
            ->whereKey($run->getKey())
            ->whereIn('status', AgentRunStatus::unsettledValues())
            ->update(['status' => AgentRunStatus::Cancelled->value, 'completed_at' => now()]);

        if ($cancelled !== 1) {
            return false;
        }

        $this->abandonRunningAttempts($run->getKey());
        $run->refresh();

        AgentRunCancelled::dispatch($run);
        $this->broadcast($run, 'settled');
        $this->dispatcher->next($run->conversation_id);

        return true;
    }

    /**
     * Only the attempt holding the claim may publish, and the reply is created
     * in the same transaction that completes the run, so delivery (queued
     * after commit) only ever sends a finished answer.
     */
    private function publish(AgentRun $run, AgentRunAttempt $attempt, AgentResult $result, BlockCollector $collector): void
    {
        $model = config('agent-chat.model', AgentRun::class);
        $blocks = $this->compose($result->text, $collector);

        $published = DB::transaction(function () use ($model, $run, $attempt, $result, $blocks, $collector) {
            $won = $model::query()
                ->whereKey($run->getKey())
                ->where('status', AgentRunStatus::Running->value)
                ->where('current_attempt_id', $attempt->getKey())
                ->whereNull('reply_message_id')
                ->update(['status' => AgentRunStatus::Completed->value, 'error' => null, 'completed_at' => now()]);

            if ($won !== 1) {
                return false;
            }

            $reply = $run->conversation->messages()->create([
                'type' => MessageType::Text,
                'direction' => MessageDirection::Outbound,
                'body' => $result->text,
                'attachments' => $collector->attachments() ?: null,
                'metadata' => ['agent_run_id' => $run->getKey(), 'harness' => $run->harness, 'blocks' => $blocks],
            ]);

            $model::query()->whereKey($run->getKey())->update(['reply_message_id' => $reply->getKey()]);
            $this->closeAttempt($attempt, AgentRunAttemptStatus::Succeeded, $result);

            return true;
        });

        $run->refresh();

        if (! $published) {
            $this->closeAttempt($attempt, AgentRunAttemptStatus::Abandoned, $result);

            return;
        }

        AgentRunCompleted::dispatch($run);
        $this->broadcast($run, 'settled');
        $this->dispatcher->next($run->conversation_id);
    }

    /**
     * Access is checked again at run time: the run may have waited in the
     * queue while the requester lost access to the owner.
     */
    private function requesterMayAct(AgentRun $run): bool
    {
        $requester = $run->requester;

        return app(OwnerAuthorizer::class)->authorize(
            $requester instanceof Authenticatable ? $requester : null,
            (string) $run->owner_type,
            $run->owner_id,
        );
    }

    private function stillOurs(AgentRun $run): bool
    {
        $model = config('agent-chat.model', AgentRun::class);

        return $model::query()
            ->whereKey($run->getKey())
            ->where('status', AgentRunStatus::Running->value)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function openAttempt(AgentRun $run, array $candidate): AgentRunAttempt
    {
        $harness = $this->agents()->harness($run->harness);

        $attempt = $run->attempts()->create([
            'number' => ((int) $run->attempts()->max('number')) + 1,
            'provider' => $candidate['provider'] ?? $harness->provider() ?? config('agents.provider'),
            'model' => $candidate['model'] ?? $harness->model() ?? config('agents.model'),
            'status' => AgentRunAttemptStatus::Running,
            'history_until_message_id' => $run->prompt_message_id,
            'lease_expires_at' => ($run->started_at ?? now())->copy()->addSeconds($this->leaseSeconds()),
            'started_at' => now(),
        ]);

        $run->forceFill(['current_attempt_id' => $attempt->getKey(), 'progress' => null])->save();

        return $attempt;
    }

    /**
     * Usage is null when the attempt failed before the provider reported it,
     * so a failure is never counted as free.
     */
    private function closeAttempt(
        AgentRunAttempt $attempt,
        AgentRunAttemptStatus $status,
        AgentResult $result,
        ?string $error = null,
        ?bool $retryable = null,
    ): void {
        $attempt->newQuery()
            ->whereKey($attempt->getKey())
            ->where('status', AgentRunAttemptStatus::Running->value)
            ->update([
                'status' => $status->value,
                'finish_reason' => $result->finishReason,
                'error' => $error,
                'retryable' => $retryable,
                'tool_calls' => json_encode($result->toolCalls),
                'usage' => $result->ok ? json_encode($result->usage) : null,
                'finished_at' => now(),
            ]);
    }

    private function abandonRunningAttempts(int|string $runId): void
    {
        $model = config('agent-chat.attempt_model', AgentRunAttempt::class);

        $model::query()
            ->where('run_id', $runId)
            ->where('status', AgentRunAttemptStatus::Running->value)
            ->update(['status' => AgentRunAttemptStatus::Abandoned->value, 'finished_at' => now()]);
    }

    private function progressFor(AgentRun $run): RunProgress
    {
        return new RunProgress([], function (array $steps, string $label, string $kind) use ($run) {
            $run->forceFill(['progress' => $steps])->save();

            if ($kind === 'started') {
                AgentRunProgressed::dispatch($run, $label);
            }

            $this->broadcast($run, 'progress', $label);
        });
    }

    private function track(RunProgress $progress, AgentStreamEvent $event): void
    {
        if ($event->toolName === null || ($label = $this->labels->for($event->toolName)) === null) {
            return;
        }

        match ($event->type) {
            StreamEventType::ToolCall => $progress->start($label),
            StreamEventType::ToolResult => $progress->finish($label),
            default => null,
        };
    }

    /**
     * Narration first, then the drawn blocks. When the agent opened a canvas,
     * the drawn blocks become one document and the chat keeps the lead-in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function compose(string $text, BlockCollector $collector): array
    {
        $blocks = trim($text) === '' ? [] : [$this->blocks->markdown(trim($text))];
        $drawn = $collector->all();

        if ($collector->canvasTitle() !== null && $drawn !== []) {
            $blocks[] = $this->blocks->canvas($collector->canvasTitle(), $drawn);

            return $blocks;
        }

        return [...$blocks, ...$drawn];
    }

    private function context(AgentRun $run, AgentRunAttempt $attempt): ToolContext
    {
        $requester = $run->requester;

        return new ToolContext(
            $requester instanceof Authenticatable ? $requester : null,
            $run->language ?? app()->getLocale(),
            [
                'conversation_id' => $run->conversation_id,
                'agent_run_id' => $run->getKey(),
                'agent_run_attempt_id' => $attempt->getKey(),
                'owner_type' => $run->owner_type,
                'owner_id' => $run->owner_id,
                'options' => $run->options ?? [],
            ],
        );
    }

    private function leaseSeconds(): int
    {
        return (int) config('agent-chat.queue.timeout', 120) + 30;
    }

    private function staleBefore(): mixed
    {
        return now()->subSeconds($this->leaseSeconds());
    }

    private function agents(): AgentManager
    {
        return app('agents');
    }

    private function broadcast(AgentRun $run, string $kind, ?string $label = null): void
    {
        if (! config('agent-chat.broadcast.enabled', false)) {
            return;
        }

        try {
            event(new AgentRunUpdated($run->conversation_id, $run->getKey(), $kind, $label));
        } catch (Throwable) {
            // A broadcaster that is down must not fail the run; clients fall back to polling.
        }
    }
}
