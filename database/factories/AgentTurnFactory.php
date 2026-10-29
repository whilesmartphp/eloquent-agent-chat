<?php

namespace Whilesmart\AgentChat\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Whilesmart\AgentChat\Enums\AgentTurnStatus;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\Conversations\Models\Conversation;

class AgentTurnFactory extends Factory
{
    protected $model = AgentTurn::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'App\\Models\\Workspace',
            'owner_id' => 1,
            'conversation_id' => fn (array $attributes) => Conversation::factory()->create([
                'owner_type' => $attributes['owner_type'],
                'owner_id' => $attributes['owner_id'],
                'channel' => 'agent',
            ])->getKey(),
            'harness' => config('agent-chat.default_harness', 'assistant'),
            'status' => AgentTurnStatus::Queued,
        ];
    }

    public function failed(string $error = 'The agent could not finish.'): static
    {
        return $this->state(fn () => [
            'status' => AgentTurnStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
