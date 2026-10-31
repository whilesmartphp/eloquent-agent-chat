<?php

namespace Whilesmart\AgentChat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Whilesmart\AgentChat\Blocks\QuestionAnswers;

class AgentRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'conversation_id' => $this->conversation_id,
            'prompt_message_id' => $this->prompt_message_id,
            'reply_message_id' => $this->reply_message_id,
            'harness' => $this->harness,
            'status' => $this->status?->value,
            'language' => $this->language,
            'options' => $this->options ?? (object) [],
            'progress' => $this->progress ?? [],
            'draft' => $this->draft,
            'error' => $this->error,
            'answers' => $this->promptMessage?->metadata['answers'] ?? null,
            'reply' => $this->whenLoaded('replyMessage', fn () => $this->replyMessage === null ? null : [
                'id' => $this->replyMessage->getKey(),
                'body' => $this->replyMessage->body,
                'blocks' => app(QuestionAnswers::class)->annotate($this->resource, $this->blocks()),
                'attachments' => $this->replyMessage->attachments ?? [],
            ]),
            'current_attempt' => $this->whenLoaded('currentAttempt', fn () => $this->currentAttempt === null
                ? null
                : app()->make(config('agent-chat.resources.attempt', AgentRunAttemptResource::class), ['resource' => $this->currentAttempt])),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
