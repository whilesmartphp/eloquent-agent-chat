<?php

namespace Whilesmart\AgentChat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentRunAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'number' => $this->number,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status?->value,
            'finish_reason' => $this->finish_reason,
            'error' => $this->error,
            'retryable' => $this->retryable,
            'tool_calls' => $this->tool_calls ?? [],
            'usage' => $this->usage,
            'history_until_message_id' => $this->history_until_message_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
