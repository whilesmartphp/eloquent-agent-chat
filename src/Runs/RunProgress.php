<?php

namespace Whilesmart\AgentChat\Runs;

use Closure;

/**
 * The steps a run shows while it works. Steps start from tool names matched
 * against the progress labels, and a tool can report its own:
 * `app(RunProgress::class)->start('Rendering the design')`, then `finish()`.
 * Outside a run the calls do nothing.
 */
class RunProgress
{
    /**
     * @param  array<int, array{label: string, state: string}>  $steps
     * @param  (Closure(array<int, array{label: string, state: string}>, string, string): void)|null  $onChange
     */
    public function __construct(
        private array $steps = [],
        private ?Closure $onChange = null,
    ) {}

    public function start(string $label): void
    {
        $label = trim($label);
        $last = end($this->steps);

        if ($label === '' || ($last !== false && $last['label'] === $label && $last['state'] === 'running')) {
            return;
        }

        if ($last !== false && $last['label'] === $label) {
            $this->steps[array_key_last($this->steps)]['state'] = 'running';
            $this->changed($label, 'resumed');

            return;
        }

        $this->steps[] = ['label' => $label, 'state' => 'running'];
        $this->changed($label, 'started');
    }

    /**
     * Mark the latest running step, or the latest one with this label, done.
     */
    public function finish(?string $label = null): void
    {
        for ($i = count($this->steps) - 1; $i >= 0; $i--) {
            $step = $this->steps[$i];

            if ($step['state'] === 'running' && ($label === null || $step['label'] === $label)) {
                $this->steps[$i]['state'] = 'done';
                $this->changed($step['label'], 'finished');

                return;
            }
        }
    }

    /**
     * @return array<int, array{label: string, state: string}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    private function changed(string $label, string $kind): void
    {
        if ($this->onChange !== null) {
            ($this->onChange)($this->steps, $label, $kind);
        }
    }
}
