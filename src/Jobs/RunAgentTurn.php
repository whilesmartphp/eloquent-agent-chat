<?php

namespace Whilesmart\AgentChat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\AgentChat\Turns\TurnRunner;

class RunAgentTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int|string $turnId)
    {
        $this->tries = (int) config('agent-chat.queue.tries', 2);
        $this->timeout = (int) config('agent-chat.queue.timeout', 120);
        $this->onConnection(config('agent-chat.queue.connection'));
        $this->onQueue(config('agent-chat.queue.queue'));
    }

    public function handle(TurnRunner $runner): void
    {
        $turn = $runner->claim($this->turnId);

        if ($turn === null) {
            return;
        }

        if (! $runner->run($turn, finalAttempt: $this->attempts() >= $this->tries)) {
            $this->release((int) config('agent-chat.queue.backoff', 10));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $turn = $this->turn();

        if ($turn !== null && ! $turn->status->isSettled()) {
            app(TurnRunner::class)->fail($turn, 'The agent stopped unexpectedly. Try again.');
        }
    }

    private function turn(): ?AgentTurn
    {
        $model = config('agent-chat.model', AgentTurn::class);

        return $model::query()->find($this->turnId);
    }
}
