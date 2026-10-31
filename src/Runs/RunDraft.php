<?php

namespace Whilesmart\AgentChat\Runs;

use Closure;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\AgentChat\Models\AgentRun;

/**
 * The reply as it is being written: the text so far and the blocks drawn so
 * far. It is saved on the run at most once per `streaming.draft_throttle_ms`
 * while the run is running, and only by the attempt holding the claim. The
 * draft is cleared when the reply is published or the run settles without
 * one; the reply message itself is still created once, at the end.
 */
class RunDraft
{
    private string $savedText = '';

    /** @var array<int, array<string, mixed>> */
    private array $savedBlocks = [];

    private ?float $savedAt = null;

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  (Closure(array<int, string>): void)|null  $onSave  Called with the kinds saved: `text`, `block`.
     */
    public function __construct(
        private AgentRun $run,
        private int|string|null $attemptId = null,
        private string $text = '',
        private array $blocks = [],
        private ?int $throttleMs = null,
        private ?Closure $onSave = null,
    ) {
        $this->savedText = $text;
        $this->savedBlocks = $blocks;
    }

    /**
     * Pick up the draft a run already has, so code outside the worker can
     * add to it.
     *
     * @param  (Closure(array<int, string>): void)|null  $onSave
     */
    public static function resume(AgentRun $run, ?Closure $onSave = null): self
    {
        $draft = is_array($run->draft) ? $run->draft : [];

        return new self(
            $run,
            $run->current_attempt_id,
            (string) ($draft['text'] ?? ''),
            array_values((array) ($draft['blocks'] ?? [])),
            0,
            $onSave,
        );
    }

    public function append(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->text .= $delta;
        $this->save();
    }

    public function replaceText(string $text): void
    {
        $this->text = $text;
        $this->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function blocks(array $blocks): void
    {
        $this->blocks = array_values($blocks);
        $this->save();
    }

    /**
     * @param  array<string, mixed>  $block
     */
    public function addBlock(array $block): void
    {
        $this->blocks[] = $block;
        $this->save();
    }

    /**
     * Save what is pending now, ignoring the throttle.
     */
    public function flush(): void
    {
        $this->save(force: true);
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->blocks;
    }

    private function save(bool $force = false): void
    {
        $kinds = array_keys(array_filter([
            'text' => $this->text !== $this->savedText,
            'block' => $this->blocks !== $this->savedBlocks,
        ]));

        if ($kinds === []) {
            return;
        }

        $now = microtime(true) * 1000;
        $throttle = $this->throttleMs ?? (int) config('agent-chat.streaming.draft_throttle_ms', 250);

        if (! $force && $this->savedAt !== null && $now - $this->savedAt < $throttle) {
            return;
        }

        $model = config('agent-chat.model', AgentRun::class);
        $saved = $model::query()
            ->whereKey($this->run->getKey())
            ->where('status', AgentRunStatus::Running->value)
            ->where(fn ($query) => $this->attemptId === null
                ? $query->whereNull('current_attempt_id')
                : $query->where('current_attempt_id', $this->attemptId))
            ->update(['draft' => json_encode([
                'attempt_id' => $this->attemptId,
                'text' => $this->text,
                'blocks' => $this->blocks,
            ])]);

        $this->savedAt = $now;
        $this->savedText = $this->text;
        $this->savedBlocks = $this->blocks;

        if ($saved === 1 && $this->onSave !== null) {
            ($this->onSave)($kinds);
        }
    }
}
