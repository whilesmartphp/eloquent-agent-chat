<?php

namespace Whilesmart\AgentChat\Runs;

use Closure;

/**
 * The steps a run shows while it works. Steps start from tool names matched
 * against the progress labels, and a tool can report its own:
 * `app(RunProgress::class)->start('Rendering the design')`, then `finish()`,
 * or `fail()` when the step went wrong. Outside a run the calls do nothing.
 *
 * Each step is `running`, `done` or `failed`; a failed step may carry a
 * `detail` saying why.
 */
class RunProgress
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @param  array<int, array{label: string, state: string, detail?: string}>  $steps
     * @param  (Closure(array<int, array{label: string, state: string, detail?: string}>, string, string): void)|null  $onChange
     */
    public function __construct(
        private array $steps = [],
        private ?Closure $onChange = null,
    ) {}

    public function start(string $label): void
    {
        $label = trim($label);
        $last = end($this->steps);

        if ($label === '' || ($last !== false && $last['label'] === $label && $last['state'] === self::RUNNING)) {
            return;
        }

        if ($last !== false && $last['label'] === $label) {
            $index = array_key_last($this->steps);
            $this->steps[$index]['state'] = self::RUNNING;
            unset($this->steps[$index]['detail']);
            $this->changed($label, 'resumed');

            return;
        }

        $this->steps[] = ['label' => $label, 'state' => self::RUNNING];
        $this->changed($label, 'started');
    }

    /**
     * Mark the latest running step, or the latest one with this label, done.
     */
    public function finish(?string $label = null): void
    {
        $this->settleLatest(self::DONE, $label);
    }

    /**
     * Mark the latest running step, or the latest one with this label,
     * failed. When no such step is running, a failed step with this label
     * is added.
     */
    public function fail(?string $label = null, ?string $detail = null): void
    {
        if ($this->settleLatest(self::FAILED, $label, $detail)) {
            return;
        }

        $label = trim((string) $label);

        if ($label !== '') {
            $this->steps[] = $this->withDetail(['label' => $label, 'state' => self::FAILED], $detail);
            $this->changed($label, 'failed');
        }
    }

    /**
     * Mark every step still running done, so no step is left hanging once
     * the run has its answer.
     */
    public function finishAll(): void
    {
        $this->settleAll(self::DONE);
    }

    /**
     * Mark every step still running failed.
     */
    public function failAll(?string $detail = null): void
    {
        $this->settleAll(self::FAILED, $detail);
    }

    /**
     * @return array<int, array{label: string, state: string, detail?: string}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * The given steps with every running step moved to `$state`.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    public static function settled(array $steps, string $state, ?string $detail = null): array
    {
        $progress = new self(array_values($steps));
        $progress->settleAll($state, $detail);

        return $progress->steps();
    }

    private function settleLatest(string $state, ?string $label, ?string $detail = null): bool
    {
        for ($i = count($this->steps) - 1; $i >= 0; $i--) {
            $step = $this->steps[$i];

            if ($step['state'] === self::RUNNING && ($label === null || $step['label'] === $label)) {
                $this->steps[$i] = $this->withDetail([...$step, 'state' => $state], $state === self::FAILED ? $detail : null);
                $this->changed($step['label'], $state === self::DONE ? 'finished' : 'failed');

                return true;
            }
        }

        return false;
    }

    private function settleAll(string $state, ?string $detail = null): void
    {
        foreach ($this->steps as $i => $step) {
            if ($step['state'] === self::RUNNING) {
                $this->steps[$i] = $this->withDetail([...$step, 'state' => $state], $state === self::FAILED ? $detail : null);
                $this->changed($step['label'], $state === self::DONE ? 'finished' : 'failed');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function withDetail(array $step, ?string $detail): array
    {
        $detail = trim((string) $detail);
        unset($step['detail']);

        return $detail === '' ? $step : [...$step, 'detail' => $detail];
    }

    private function changed(string $label, string $kind): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this->steps, $label, $kind);
        }
    }
}
