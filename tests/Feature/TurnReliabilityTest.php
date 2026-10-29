<?php

namespace Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\User;
use Tests\TestCase;
use Whilesmart\AgentChat\Jobs\RunAgentTurn;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\AgentChat\Turns\TurnRunner;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Conversations\Models\Message;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class TurnReliabilityTest extends TestCase
{
    private function queuedTurn(User $requester, array $attributes = []): AgentTurn
    {
        $conversation = $this->conversation();
        $prompt = $conversation->messages()->create([
            'author_type' => $requester->getMorphClass(),
            'author_id' => $requester->id,
            'body' => 'Write the report',
        ]);

        return AgentTurn::create([
            'owner_type' => self::OWNER,
            'owner_id' => 1,
            'requester_type' => $requester->getMorphClass(),
            'requester_id' => $requester->id,
            'conversation_id' => $conversation->id,
            'prompt_message_id' => $prompt->id,
            'harness' => 'assistant',
            ...$attributes,
        ]);
    }

    private function denyEveryone(): void
    {
        $this->app->instance(OwnerAuthorizer::class, new class implements OwnerAuthorizer
        {
            public function authorize(?Authenticatable $user, string $ownerType, mixed $ownerId): bool
            {
                return false;
            }

            public function scope(Builder $query, ?Authenticatable $user, string $ownerTypeColumn = 'owner_type', string $ownerIdColumn = 'owner_id'): Builder
            {
                return $query->whereRaw('0 = 1');
            }
        });
    }

    #[Test]
    public function another_member_cannot_retry_a_turn_that_runs_with_someone_elses_access(): void
    {
        $alice = $this->user('Alice');
        $turn = $this->queuedTurn($alice, ['status' => 'failed', 'error' => 'Provider timed out.']);

        $this->actingAs($this->user('Bob'))
            ->postJson("/api/agent-turns/{$turn->id}/retry")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the person who asked can retry this turn.');

        $this->assertSame('failed', $turn->fresh()->status->value);
        $this->assertSame([], $this->harness->inputs);
    }

    #[Test]
    public function a_queued_turn_fails_without_running_when_the_requester_has_lost_access(): void
    {
        $turn = $this->queuedTurn($this->user());
        $this->denyEveryone();

        (new RunAgentTurn($turn->id))->handle(app(TurnRunner::class));

        $turn->refresh();
        $this->assertSame('failed', $turn->status->value);
        $this->assertSame('The person who asked no longer has access to this conversation.', $turn->error);
        $this->assertSame([], $this->harness->inputs);
        $this->assertNull($turn->reply_message_id);
    }

    #[Test]
    public function a_turn_another_worker_is_running_is_not_run_again(): void
    {
        $turn = $this->queuedTurn($this->user(), ['status' => 'running', 'started_at' => now()]);

        dispatch_sync(new RunAgentTurn($turn->id));
        (new RunAgentTurn($turn->id))->handle(app(TurnRunner::class));

        $this->assertSame([], $this->harness->inputs);
        $this->assertSame('running', $turn->fresh()->status->value);
    }

    #[Test]
    public function a_turn_whose_worker_died_is_picked_up_once_the_timeout_has_passed(): void
    {
        $turn = $this->queuedTurn($this->user(), ['status' => 'running', 'started_at' => now()->subMinutes(10)]);

        dispatch_sync(new RunAgentTurn($turn->id));
        dispatch_sync(new RunAgentTurn($turn->id));

        $this->assertCount(1, $this->harness->inputs);
        $this->assertSame('completed', $turn->fresh()->status->value);
        $this->assertSame(1, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function a_settled_turn_is_never_run_again(): void
    {
        $turn = $this->queuedTurn($this->user());

        dispatch_sync(new RunAgentTurn($turn->id));
        dispatch_sync(new RunAgentTurn($turn->id));

        $this->assertCount(1, $this->harness->inputs);
        $this->assertSame(1, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function a_failed_attempt_goes_back_to_the_queue_while_tries_remain(): void
    {
        config(['agent-chat.queue.tries' => 2, 'agent-chat.queue.backoff' => 15]);
        $this->harness->script(fn () => AgentResult::failed('Provider timed out.'));
        $turn = $this->queuedTurn($this->user());

        $job = (new RunAgentTurn($turn->id))->withFakeQueueInteractions();
        $job->handle(app(TurnRunner::class));

        $job->assertReleased(15);
        $this->assertSame('queued', $turn->fresh()->status->value);

        $this->harness->script(fn () => AgentResult::success('Back online.'));
        $job = (new RunAgentTurn($turn->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(TurnRunner::class));

        $job->assertNotReleased();
        $this->assertSame('completed', $turn->fresh()->status->value);
        $this->assertNull($turn->fresh()->error);
    }

    #[Test]
    public function the_last_attempt_fails_the_turn_instead_of_releasing_it(): void
    {
        config(['agent-chat.queue.tries' => 2]);
        $this->harness->script(fn () => AgentResult::failed('Provider timed out.'));
        $turn = $this->queuedTurn($this->user());

        $job = (new RunAgentTurn($turn->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(TurnRunner::class));

        $job->assertNotReleased();
        $this->assertSame('failed', $turn->fresh()->status->value);
        $this->assertSame('Provider timed out.', $turn->fresh()->error);
    }

    #[Test]
    public function an_answer_cut_off_mid_stream_fails_the_turn_instead_of_posting_it(): void
    {
        $this->harness->script(fn () => new AgentResult(true, 'Half a rep', finishReason: 'error'));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-turns", ['body' => 'Report'])
            ->json('data.id');

        $this->getJson("/api/agent-turns/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'The answer was cut off before it finished.')
            ->assertJsonPath('data.reply', null);
        $this->assertSame(0, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function a_turn_that_cannot_be_queued_is_failed_so_it_can_be_retried(): void
    {
        $user = $this->user();
        $conversation = $this->conversation();
        config(['queue.default' => 'missing']);

        $id = $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/agent-turns", ['body' => 'Report'])
            ->assertStatus(202)
            ->json('data.id');

        $this->assertSame('failed', AgentTurn::find($id)->status->value);
        $this->assertSame('The turn could not be queued. Try again.', AgentTurn::find($id)->error);

        config(['queue.default' => 'sync']);
        $this->postJson("/api/agent-turns/{$id}/retry")->assertStatus(202);

        $this->assertSame('completed', AgentTurn::find($id)->status->value);
    }
}
