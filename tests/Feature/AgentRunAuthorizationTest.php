<?php

namespace Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Conversations\Models\Message;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class AgentRunAuthorizationTest extends TestCase
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
    public function a_run_cannot_be_started_in_a_conversation_the_user_cannot_reach(): void
    {
        $foreign = $this->conversation(ownerId: 2);

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$foreign->id}/agent-runs", ['body' => 'Show me their data'])
            ->assertForbidden();

        $this->assertSame(0, AgentRun::count());
        $this->assertSame(0, Message::count());
        $this->assertSame([], $this->harness->inputs);
    }

    #[Test]
    public function show_retry_and_export_are_forbidden_on_a_run_the_user_cannot_reach(): void
    {
        $run = AgentRun::factory()->failed()->create(['owner_id' => 2]);
        $this->actingAs($this->user());

        $this->getJson("/api/agent-runs/{$run->id}")->assertForbidden();
        $this->postJson("/api/agent-runs/{$run->id}/retry")->assertForbidden();
        $this->get("/api/agent-runs/{$run->id}/export")->assertForbidden();

        $this->assertSame('failed', $run->fresh()->status->value);
    }

    #[Test]
    public function index_only_lists_runs_the_user_can_reach(): void
    {
        $mine = AgentRun::factory()->create(['owner_id' => 1]);
        AgentRun::factory()->create(['owner_id' => 2]);

        $this->actingAs($this->user())
            ->getJson('/api/agent-runs')
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine->id);
    }
}
