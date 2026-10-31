<?php

namespace Whilesmart\AgentChat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Live update for a run on the conversation's private channel. Broadcast now,
 * not queued: the emitting job already holds a worker, so a queued broadcast
 * would only leave once the run had finished.
 */
class AgentRunUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public int|string $conversationId,
        public int|string $runId,
        public string $kind,
        public ?string $label = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $prefix = config('agent-chat.broadcast.channel_prefix', 'agent-chat.conversation');

        return [new PrivateChannel("{$prefix}.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'run';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['run_id' => $this->runId, 'kind' => $this->kind, 'label' => $this->label];
    }
}
