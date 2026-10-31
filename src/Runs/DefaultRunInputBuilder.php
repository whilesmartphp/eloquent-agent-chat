<?php

namespace Whilesmart\AgentChat\Runs;

use Whilesmart\AgentChat\Contracts\RunInputBuilder;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Conversations\Enums\MessageDirection;
use Whilesmart\Conversations\Enums\MessageType;
use Whilesmart\Conversations\Models\Message;

/**
 * Folds the recent conversation into the input so a follow-up such as "make
 * it shorter" is read in context, and tells the agent when an earlier canvas
 * exists so it edits that one instead of starting a new document.
 *
 * Agent replies are recognised by the run that wrote them. A message with no
 * author and no run came from an outside contact or the team, never the agent.
 * Attached files are named in the text; the media resolver decides what the
 * model actually sees.
 */
class DefaultRunInputBuilder implements RunInputBuilder
{
    public function __construct(private ConversationCanvas $canvases) {}

    public function build(AgentRun $run): string
    {
        $parts = [];
        $prompt = $run->promptMessage;
        $messageModel = config('conversations.message_model', Message::class);

        $history = $messageModel::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('type', MessageType::Text->value)
            ->when($prompt !== null, fn ($query) => $query->where('id', '<', $prompt->getKey()))
            ->latest('id')
            ->limit(max(0, (int) config('agent-chat.history_limit', 10)))
            ->get()
            ->reverse();

        $lines = $history
            ->map(fn (Message $message) => $this->line($this->speaker($message), $message))
            ->filter();

        if ($lines->isNotEmpty()) {
            $parts[] = "Conversation so far:\n".$lines->implode("\n");
        }

        $canvas = $this->canvases->latest($run->conversation_id, $run->getKey());

        if ($canvas !== null) {
            $parts[] = 'A canvas titled "'.($canvas['title'] ?? 'Report').'" exists from an earlier run. '
                .'To change or extend it, call update_canvas rather than open_canvas.';
        }

        $parts[] = $prompt === null ? 'User:' : (string) $this->line('User', $prompt);

        return implode("\n\n", $parts);
    }

    protected function speaker(Message $message): string
    {
        if (isset($message->metadata['agent_run_id'])) {
            return 'Assistant';
        }

        if ($message->author_type !== null) {
            return 'User';
        }

        return $message->direction === MessageDirection::Inbound ? 'Contact' : 'Team';
    }

    protected function line(string $speaker, Message $message): ?string
    {
        $text = trim((string) $message->body);
        $files = [];

        foreach ((array) ($message->attachments ?? []) as $attachment) {
            if (is_array($attachment)) {
                $files[] = '['.($attachment['type'] ?? 'file').': '.($attachment['name'] ?? 'unnamed').']';
            }
        }

        $content = trim($text.' '.implode(' ', $files));

        return $content === '' ? null : "{$speaker}: {$content}";
    }
}
