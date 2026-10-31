<?php

namespace Whilesmart\AgentChat\Runs;

use Whilesmart\AgentChat\Models\AgentRun;

/**
 * Finds the canvas documents earlier runs produced in a conversation.
 */
class ConversationCanvas
{
    /**
     * @return array<string, mixed>|null
     */
    public function latest(int|string $conversationId, int|string|null $before = null): ?array
    {
        $model = config('agent-chat.model', AgentRun::class);

        $runs = $model::query()
            ->where('conversation_id', $conversationId)
            ->whereNotNull('reply_message_id')
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->latest('id')
            ->with('replyMessage')
            ->get();

        foreach ($runs as $run) {
            if (($canvas = $run->canvas()) !== null) {
                return $canvas;
            }
        }

        return null;
    }
}
