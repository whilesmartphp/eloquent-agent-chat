<?php

namespace Whilesmart\AgentChat\Runs;

use Throwable;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\AgentChat\Enums\ConcurrencyPolicy;
use Whilesmart\AgentChat\Events\AgentRunQueued;
use Whilesmart\AgentChat\Jobs\RunAgent;
use Whilesmart\AgentChat\Models\AgentRun;

/**
 * Queues runs and applies the conversation's concurrency policy.
 */
class RunDispatcher
{
    public function policy(): ConcurrencyPolicy
    {
        return ConcurrencyPolicy::from((string) config('agent-chat.concurrency', 'queue'));
    }

    /**
     * A run whose job never reached the queue is marked failed, so the
     * requester can retry it instead of it waiting forever.
     */
    public function queue(AgentRun $run): void
    {
        try {
            RunAgent::dispatch($run->getKey());
        } catch (Throwable $e) {
            report($e);
            app(RunExecutor::class)->fail($run, 'The run could not be queued. Try again.');

            return;
        }

        AgentRunQueued::dispatch($run);
    }

    /**
     * Whether another run in the conversation has not settled yet. Used to
     * refuse a prompt under the reject policy. Followed runs track work
     * outside the agent, so they never hold up a prompt.
     */
    public function busy(int|string $conversationId): bool
    {
        $model = config('agent-chat.model', AgentRun::class);

        return $model::query()
            ->where('conversation_id', $conversationId)
            ->where('harness', 'not like', AgentRun::FOLLOW_PREFIX.'%')
            ->whereIn('status', [AgentRunStatus::Held->value, AgentRunStatus::Queued->value, AgentRunStatus::Running->value, AgentRunStatus::Waiting->value])
            ->exists();
    }

    /**
     * Under the queue policy a run waits while an earlier run in the same
     * conversation is queued or running. Its job ends without running; the
     * earlier run queues it again when it settles.
     */
    public function mustWait(AgentRun $run): bool
    {
        if ($this->policy() !== ConcurrencyPolicy::Queue) {
            return false;
        }

        $model = config('agent-chat.model', AgentRun::class);

        return $model::query()
            ->where('conversation_id', $run->conversation_id)
            ->whereKeyNot($run->getKey())
            ->where('harness', 'not like', AgentRun::FOLLOW_PREFIX.'%')
            ->where(fn ($query) => $query
                ->where('status', AgentRunStatus::Running->value)
                ->orWhere(fn ($query) => $query
                    ->where('status', AgentRunStatus::Queued->value)
                    ->where('id', '<', $run->getKey())))
            ->exists();
    }

    /**
     * Queue the next waiting run in the conversation once one settles.
     */
    public function next(int|string $conversationId): void
    {
        if ($this->policy() !== ConcurrencyPolicy::Queue) {
            return;
        }

        $model = config('agent-chat.model', AgentRun::class);
        $next = $model::query()
            ->where('conversation_id', $conversationId)
            ->where('status', AgentRunStatus::Queued->value)
            ->oldest('id')
            ->first();

        if ($next !== null) {
            $this->queue($next);
        }
    }
}
