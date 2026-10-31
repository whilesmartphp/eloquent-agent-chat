<?php

namespace Whilesmart\AgentChat\Runs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Whilesmart\AgentChat\Blocks\BlockBuilder;
use Whilesmart\AgentChat\Blocks\BlockCollector;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\AgentChat\Events\AgentRunProgressed;
use Whilesmart\AgentChat\Events\AgentRunStarted;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

/**
 * Shows work done outside the agent, such as a video render, as a run in the
 * conversation. Host code starts the run, reports steps, text and blocks as
 * the work goes, and completes or fails it. No attempt is opened and no
 * harness runs; the run's harness reads `follow:<name>`.
 *
 * The run fires the same events and broadcasts as an agent run, and the
 * reply is published by the executor under the same transaction rules.
 * Every call does nothing once the run has settled, for example after the
 * requester cancelled it, so a job can check `status` and stop. A followed
 * run cannot be retried and never holds up prompts in its conversation.
 */
class RunFollower
{
    public function __construct(
        private RunExecutor $executor,
        private BlockBuilder $blocks,
    ) {}

    /**
     * Start a running run in the conversation, optionally with a first step.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws AuthorizationException when the requester cannot reach the conversation's owner.
     */
    public function start(
        Model $conversation,
        ?Authenticatable $requester,
        string $name,
        ?string $label = null,
        array $options = [],
        int|string|null $promptMessageId = null,
        ?string $language = null,
    ): AgentRun {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A followed run needs a name, such as "render".');
        }

        if ($requester !== null && ! app(OwnerAuthorizer::class)->authorize($requester, (string) $conversation->owner_type, $conversation->owner_id)) {
            throw new AuthorizationException('The requester cannot reach this conversation.');
        }

        $model = config('agent-chat.model', AgentRun::class);

        $run = $model::create([
            'owner_type' => $conversation->owner_type,
            'owner_id' => $conversation->owner_id,
            'requester_type' => $requester?->getMorphClass(),
            'requester_id' => $requester?->getAuthIdentifier(),
            'conversation_id' => $conversation->getKey(),
            'prompt_message_id' => $promptMessageId,
            'harness' => AgentRun::FOLLOW_PREFIX.$name,
            'status' => AgentRunStatus::Running,
            'language' => $language,
            'options' => $options === [] ? null : $options,
            'queued_at' => now(),
            'started_at' => now(),
        ])->fresh();

        AgentRunStarted::dispatch($run);
        $this->executor->broadcast($run, 'started');

        if ($label !== null) {
            $this->progress($run)->start($label);
        }

        return $run->fresh();
    }

    /**
     * The run's steps. `start()`, `finish()` and `fail()` on the returned
     * progress are saved on the run and announced.
     */
    public function progress(AgentRun|int|string $run): RunProgress
    {
        $run = $this->find($run);

        return new RunProgress((array) ($run->progress ?? []), function (array $steps, string $label, string $kind) use ($run) {
            if (! $this->save($run, ['progress' => json_encode($steps)])) {
                return;
            }

            if ($kind === 'started') {
                AgentRunProgressed::dispatch($run->refresh(), $label);
            }

            $this->executor->broadcast($run, 'progress', $label);
        });
    }

    /**
     * Replace the draft's text, such as a status line the work reports.
     */
    public function text(AgentRun|int|string $run, string $text): void
    {
        $this->draft($this->find($run))->replaceText($text);
    }

    /**
     * Add a block to the draft. It is checked like any block a tool adds.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function block(AgentRun|int|string $run, array $block): array
    {
        $block = BlockCollector::normalize($block);
        $this->draft($this->find($run))->addBlock($block);

        return $block;
    }

    /**
     * Add a question block to the draft, with the same options as
     * `ask_question`. Returns the block, with its id.
     *
     * @param  array<int, array{id?: string, label?: string, message?: string}|string>  $options
     * @return array<string, mixed>
     */
    public function ask(
        AgentRun|int|string $run,
        string $prompt,
        array $options,
        ?string $id = null,
        bool $multiple = false,
        bool|string $writeIn = false,
        ?string $why = null,
        ?string $recommended = null,
    ): array {
        return $this->block($run, $this->blocks->question($prompt, $options, $id, $multiple, $writeIn, $why, $recommended));
    }

    /**
     * Publish the reply: the text, then the draft's blocks, then `$blocks`,
     * with `$attachments` attached (images are also shown). Steps still
     * running are marked done. Returns false when the run had settled.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function complete(AgentRun|int|string $run, string $text = '', array $blocks = [], array $attachments = []): bool
    {
        $run = $this->find($run);

        if ($run->status !== AgentRunStatus::Running) {
            return false;
        }

        $collector = new BlockCollector;

        foreach ([...array_values((array) ($run->draft['blocks'] ?? [])), ...$blocks] as $block) {
            $collector->add($block);
        }

        foreach ($attachments as $attachment) {
            $collector->attach($attachment);
        }

        $this->progress($run)->finishAll();

        return $this->executor->publishReply($run, $text, $collector);
    }

    /**
     * Settle the run as failed with a reason the person can read. Steps
     * still running are marked failed.
     */
    public function fail(AgentRun|int|string $run, string $reason): void
    {
        $this->executor->fail($this->find($run), $reason);
    }

    private function find(AgentRun|int|string $run): AgentRun
    {
        $model = config('agent-chat.model', AgentRun::class);
        $run = $model::query()->findOrFail($run instanceof AgentRun ? $run->getKey() : $run);

        if (! $run->isFollowed()) {
            throw new InvalidArgumentException('Run '.$run->getKey().' is run by a harness, not followed.');
        }

        return $run;
    }

    private function draft(AgentRun $run): RunDraft
    {
        return RunDraft::resume($run, function (array $kinds) use ($run) {
            foreach ($kinds as $kind) {
                $this->executor->broadcast($run, $kind);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function save(AgentRun $run, array $changes): bool
    {
        $model = config('agent-chat.model', AgentRun::class);

        return $model::query()
            ->whereKey($run->getKey())
            ->where('status', AgentRunStatus::Running->value)
            ->update($changes) === 1;
    }
}
