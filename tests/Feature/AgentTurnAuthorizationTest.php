<?php

namespace Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\Conversations\Models\Message;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class AgentTurnAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OwnerAuthorizer::class, new class implements OwnerAuthorizer
        {
            public function authorize(?Authenticatable $user, string $ownerType, mixed $ownerId): bool
            {
                return (int) $ownerId === 1;
            }

            public function scope(Builder $query, ?Authenticatable $user, string $ownerTypeColumn = 'owner_type', string $ownerIdColumn = 'owner_id'): Builder
            {
                return $query->where($ownerIdColumn, 1);
            }
        });
    }

    #[Test]
    public function a_turn_cannot_be_started_in_a_conversation_the_user_cannot_reach(): void
    {
        $foreign = $this->conversation(ownerId: 2);

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$foreign->id}/agent-turns", ['body' => 'Show me their data'])
            ->assertForbidden();

        $this->assertSame(0, AgentTurn::count());
        $this->assertSame(0, Message::count());
        $this->assertSame([], $this->harness->inputs);
    }

    #[Test]
    public function show_retry_and_export_are_forbidden_on_a_turn_the_user_cannot_reach(): void
    {
        $turn = AgentTurn::factory()->failed()->create(['owner_id' => 2]);
        $this->actingAs($this->user());

        $this->getJson("/api/agent-turns/{$turn->id}")->assertForbidden();
        $this->postJson("/api/agent-turns/{$turn->id}/retry")->assertForbidden();
        $this->get("/api/agent-turns/{$turn->id}/export")->assertForbidden();

        $this->assertSame('failed', $turn->fresh()->status->value);
    }

    #[Test]
    public function index_only_lists_turns_the_user_can_reach(): void
    {
        $mine = AgentTurn::factory()->create(['owner_id' => 1]);
        AgentTurn::factory()->create(['owner_id' => 2]);

        $this->actingAs($this->user())
            ->getJson('/api/agent-turns')
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine->id);
    }
}
