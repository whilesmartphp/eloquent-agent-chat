<?php

namespace Whilesmart\AgentChat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Runs\RunExecutor;

class RunAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int|string $runId)
    {
        $this->tries = (int) config('agent-chat.queue.tries', 2);
        $this->timeout = (int) config('agent-chat.queue.timeout', 120);
        $this->onConnection(config('agent-chat.queue.connection'));
        $this->onQueue(config('agent-chat.queue.queue'));
    }

    public function handle(RunExecutor $executor): void
    {
        $run = $executor->claim($this->runId);

        if ($run === null) {
            return;
        }

        if (! $executor->execute($run, finalTry: $this->attempts() >= $this->tries)) {
            $this->release((int) config('agent-chat.queue.backoff', 10));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $model = config('agent-chat.model', AgentRun::class);
        $run = $model::query()->find($this->runId);

        if ($run !== null && ! $run->status->isSettled()) {
            app(RunExecutor::class)->fail($run, 'The agent stopped unexpectedly. Try again.');
        }
    }
}
