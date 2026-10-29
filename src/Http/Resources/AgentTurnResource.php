<?php

namespace Whilesmart\AgentChat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentTurnResource extends JsonResource
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
            'progress' => $this->progress ?? [],
            'error' => $this->error,
            'reply' => $this->whenLoaded('replyMessage', fn () => $this->replyMessage === null ? null : [
                'id' => $this->replyMessage->getKey(),
                'body' => $this->replyMessage->body,
                'blocks' => $this->blocks(),
            ]),
            'usage' => $this->usage,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
