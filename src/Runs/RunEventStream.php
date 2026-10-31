<?php

namespace Whilesmart\AgentChat\Runs;

use Generator;
use Illuminate\Support\Sleep;
use Whilesmart\AgentChat\Models\AgentRun;

/**
 * The events a client follows a run by. It polls the stored run, so it works
 * with any queue and worker, and yields only what changed since the last
 * poll. `text` always carries the whole text so far, so a client that
 * missed one simply takes the latest.
 *
 * Events, in the order a run produces them: `started`, `progress`, `text`,
 * `block`, `reset` (a new attempt started over), then `settled`, or
 * `timeout` once `streaming.max_seconds` passed first.
 */
class RunEventStream
{
    /**
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public function events(AgentRun $run): Generator
    {
        $model = config('agent-chat.model', AgentRun::class);
        $pollMs = max(10, (int) config('agent-chat.streaming.poll_ms', 500));
        $deadline = now()->addSeconds(max(1, (int) config('agent-chat.streaming.max_seconds', 120)));

        $started = false;
        $steps = null;
        $text = '';
        $sent = 0;
        $attemptId = null;

        while (true) {
            $run = $model::query()->find($run->getKey());

            if ($run === null) {
                return;
            }

            if (! $started && $run->started_at !== null) {
                $started = true;
                yield ['started', [
                    'run_id' => $run->getKey(),
                    'status' => $run->status->value,
                    'harness' => $run->harness,
                    'started_at' => $run->started_at->toIso8601String(),
                ]];
            }

            if (($run->progress ?? []) !== ($steps ?? [])) {
                $steps = $run->progress ?? [];
                yield ['progress', ['steps' => $steps]];
            }

            $draft = is_array($run->draft) ? $run->draft : null;

            if ($draft !== null) {
                $blocks = array_values((array) ($draft['blocks'] ?? []));

                if (($attemptId !== null && ($draft['attempt_id'] ?? null) !== $attemptId) || count($blocks) < $sent) {
                    yield ['reset', ['attempt_id' => $draft['attempt_id'] ?? null]];
                    $text = '';
                    $sent = 0;
                }

                $attemptId = $draft['attempt_id'] ?? null;

                if ((string) ($draft['text'] ?? '') !== $text) {
                    $text = (string) $draft['text'];
                    yield ['text', ['text' => $text]];
                }

                for (; $sent < count($blocks); $sent++) {
                    yield ['block', ['index' => $sent, 'block' => $blocks[$sent]]];
                }
            }

            if ($run->status->isSettled()) {
                yield ['settled', [
                    'status' => $run->status->value,
                    'reply_message_id' => $run->reply_message_id,
                    'error' => $run->error,
                ]];

                return;
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                yield ['timeout', ['status' => $run->status->value]];

                return;
            }

            Sleep::for($pollMs)->milliseconds();
        }
    }
}
