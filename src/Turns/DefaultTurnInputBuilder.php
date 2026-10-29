<?php

namespace Whilesmart\AgentChat\Turns;

use Whilesmart\AgentChat\Contracts\TurnInputBuilder;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\Conversations\Models\Message;

/**
 * Folds the recent conversation into the input so a follow-up such as "make
 * it shorter" is read in context, and tells the agent when an earlier canvas
 * exists so it edits that one instead of starting a new document.
 */
class DefaultTurnInputBuilder implements TurnInputBuilder
{
    public function __construct(private ConversationCanvas $canvases) {}

    public function build(AgentTurn $turn): string
    {
        $parts = [];
        $prompt = $turn->promptMessage;
        $messageModel = config('conversations.message_model', Message::class);

        $history = $messageModel::query()
            ->where('conversation_id', $turn->conversation_id)
            ->when($prompt !== null, fn ($query) => $query->where('id', '<', $prompt->getKey()))
            ->whereNotNull('body')
            ->latest('id')
            ->limit(max(0, (int) config('agent-chat.history_limit', 10)))
            ->get()
            ->reverse();

        $lines = $history
            ->filter(fn ($message) => trim((string) $message->body) !== '')
            ->map(fn ($message) => ($message->author_type === null ? 'Assistant' : 'User').': '.trim((string) $message->body));

        if ($lines->isNotEmpty()) {
            $parts[] = "Conversation so far:\n".$lines->implode("\n");
        }

        $canvas = $this->canvases->latest($turn->conversation_id, $turn->getKey());

        if ($canvas !== null) {
            $parts[] = 'A canvas titled "'.($canvas['title'] ?? 'Report').'" exists from an earlier turn. '
                .'To change or extend it, call update_canvas rather than open_canvas.';
        }

        $parts[] = 'User: '.trim((string) $prompt?->body);

        return implode("\n\n", $parts);
    }
}
