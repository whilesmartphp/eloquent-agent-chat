<?php

namespace Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\User;
use Tests\TestCase;
use Whilesmart\AgentChat\Jobs\RunAgent;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Runs\RunExecutor;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Conversations\Models\Message;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class RunReliabilityTest extends TestCase
{
    private function queuedRun(User $requester, array $attributes = []): AgentRun
    {
        $conversation = $this->conversation();
        $prompt = $conversation->messages()->create([
            'author_type' => $requester->getMorphClass(),
            'author_id' => $requester->id,
            'body' => 'Write the report',
        ]);

        return AgentRun::create([
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
    public function another_member_cannot_retry_a_run_that_runs_with_someone_elses_access(): void
    {
        $alice = $this->user('Alice');
        $run = $this->queuedRun($alice, ['status' => 'failed', 'error' => 'Provider timed out.']);

        $this->actingAs($this->user('Bob'))
            ->postJson("/api/agent-runs/{$run->id}/retry")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the person who asked can do this.');

        $this->assertSame('failed', $run->fresh()->status->value);
        $this->assertSame([], $this->harness->inputs);
    }

    #[Test]
    public function a_queued_run_fails_without_running_when_the_requester_has_lost_access(): void
    {
        $run = $this->queuedRun($this->user());
        $this->denyEveryone();

        (new RunAgent($run->id))->handle(app(RunExecutor::class));

        $run->refresh();
        $this->assertSame('failed', $run->status->value);
        $this->assertSame('The person who asked no longer has access to this conversation.', $run->error);
        $this->assertSame([], $this->harness->inputs);
        $this->assertNull($run->reply_message_id);
    }

    #[Test]
    public function a_run_another_worker_is_running_is_not_run_again(): void
    {
        $run = $this->queuedRun($this->user(), ['status' => 'running', 'started_at' => now()]);

        dispatch_sync(new RunAgent($run->id));
        (new RunAgent($run->id))->handle(app(RunExecutor::class));

        $this->assertSame([], $this->harness->inputs);
        $this->assertSame('running', $run->fresh()->status->value);
    }

    #[Test]
    public function a_run_whose_worker_died_is_picked_up_once_the_timeout_has_passed(): void
    {
        $run = $this->queuedRun($this->user(), ['status' => 'running', 'started_at' => now()->subMinutes(10)]);

        dispatch_sync(new RunAgent($run->id));
        dispatch_sync(new RunAgent($run->id));

        $this->assertCount(1, $this->harness->inputs);
        $this->assertSame('completed', $run->fresh()->status->value);
        $this->assertSame(1, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function a_settled_run_is_never_run_again(): void
    {
        $run = $this->queuedRun($this->user());

        dispatch_sync(new RunAgent($run->id));
        dispatch_sync(new RunAgent($run->id));

        $this->assertCount(1, $this->harness->inputs);
        $this->assertSame(1, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function a_failed_attempt_goes_back_to_the_queue_while_tries_remain(): void
    {
        config(['agent-chat.queue.tries' => 2, 'agent-chat.queue.backoff' => 15]);
        $this->harness->script(fn () => AgentResult::failed('Provider timed out.'));
        $run = $this->queuedRun($this->user());

        $job = (new RunAgent($run->id))->withFakeQueueInteractions();
        $job->handle(app(RunExecutor::class));

        $job->assertReleased(15);
        $this->assertSame('queued', $run->fresh()->status->value);

        $this->harness->script(fn () => AgentResult::success('Back online.'));
        $job = (new RunAgent($run->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(RunExecutor::class));

        $job->assertNotReleased();
        $this->assertSame('completed', $run->fresh()->status->value);
        $this->assertNull($run->fresh()->error);
    }

    #[Test]
    public function the_last_attempt_fails_the_run_instead_of_releasing_it(): void
    {
        config(['agent-chat.queue.tries' => 2]);
        $this->harness->script(fn () => AgentResult::failed('Provider timed out.'));
        $run = $this->queuedRun($this->user());

        $job = (new RunAgent($run->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle(app(RunExecutor::class));

        $job->assertNotReleased();
        $this->assertSame('failed', $run->fresh()->status->value);
        $this->assertSame('Provider timed out.', $run->fresh()->error);
    }

    #[Test]
    public function an_answer_cut_off_mid_stream_fails_the_run_instead_of_posting_it(): void
    {
        $this->harness->script(fn () => new AgentResult(true, 'Half a rep', finishReason: 'error'));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'The answer was cut off before it finished.')
            ->assertJsonPath('data.reply', null);
        $this->assertSame(0, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function a_run_that_cannot_be_queued_is_failed_so_it_can_be_retried(): void
    {
        $user = $this->user();
        $conversation = $this->conversation();
        config(['queue.default' => 'missing']);

        $id = $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Report'])
            ->assertStatus(202)
            ->json('data.id');

        $this->assertSame('failed', AgentRun::find($id)->status->value);
        $this->assertSame('The run could not be queued. Try again.', AgentRun::find($id)->error);

        config(['queue.default' => 'sync']);
        $this->postJson("/api/agent-runs/{$id}/retry")->assertStatus(202);

        $this->assertSame('completed', AgentRun::find($id)->status->value);
    }

    #[Test]
    public function the_attempt_of_a_worker_that_died_is_abandoned_and_a_new_one_opens(): void
    {
        $run = $this->queuedRun($this->user(), ['status' => 'running', 'started_at' => now()->subMinutes(10)]);
        $dead = $run->attempts()->create(['number' => 1, 'status' => 'running', 'started_at' => now()->subMinutes(10)]);
        $run->forceFill(['current_attempt_id' => $dead->id])->save();

        dispatch_sync(new RunAgent($run->id));

        $this->assertSame('abandoned', $dead->fresh()->status->value);
        $this->assertSame(
            [[1, 'abandoned'], [2, 'succeeded']],
            $run->attempts()->orderBy('number')->get()->map(fn ($attempt) => [$attempt->number, $attempt->status->value])->all(),
        );
        $this->assertSame('completed', $run->fresh()->status->value);
    }
}
