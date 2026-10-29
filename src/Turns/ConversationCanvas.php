<?php

namespace Whilesmart\AgentChat\Turns;

use Whilesmart\AgentChat\Models\AgentTurn;

/**
 * Finds the canvas documents earlier turns produced in a conversation.
 */
class ConversationCanvas
{
    /**
     * @return array<string, mixed>|null
     */
    public function latest(int|string $conversationId, int|string|null $before = null): ?array
    {
        $model = config('agent-chat.model', AgentTurn::class);

        $turns = $model::query()
            ->where('conversation_id', $conversationId)
            ->whereNotNull('reply_message_id')
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->latest('id')
            ->with('replyMessage')
            ->get();

        foreach ($turns as $turn) {
            if (($canvas = $turn->canvas()) !== null) {
                return $canvas;
            }
        }

        return null;
    }
}
