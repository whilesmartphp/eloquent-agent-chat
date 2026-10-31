<?php

namespace Whilesmart\AgentChat\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Conversations\Models\Conversation;

class AgentRunFactory extends Factory
{
    protected $model = AgentRun::class;

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
            'status' => AgentRunStatus::Queued,
            'queued_at' => now(),
        ];
    }

    public function failed(string $error = 'The agent could not finish.'): static
    {
        return $this->state(fn () => [
            'status' => AgentRunStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
