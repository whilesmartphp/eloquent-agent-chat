<?php

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentChat\Events\AgentRunCompleted;
use Whilesmart\AgentChat\Events\AgentRunFailed;
use Whilesmart\AgentChat\Events\AgentRunProgressed;
use Whilesmart\AgentChat\Events\AgentRunStarted;
use Whilesmart\AgentChat\Events\AgentRunUpdated;
use Whilesmart\AgentChat\Jobs\RunAgent;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Runs\RunExecutor;
use Whilesmart\AgentChat\Runs\RunFollower;
use Whilesmart\Conversations\Models\Message;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

class FollowRunsTest extends TestCase
{
    private function follower(): RunFollower
    {
        return app(RunFollower::class);
    }

    #[Test]
    public function a_followed_run_shows_outside_work_from_start_to_reply(): void
    {
        config(['agent-chat.broadcast.enabled' => true]);
        Event::fake([AgentRunStarted::class, AgentRunProgressed::class, AgentRunCompleted::class, AgentRunUpdated::class]);
        $dee = $this->user();
        $conversation = $this->conversation();

        $run = $this->follower()->start($conversation, $dee, 'render', 'Rendering the film', ['film_id' => 12]);

        $this->actingAs($dee)->getJson("/api/agent-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.harness', 'follow:render')
            ->assertJsonPath('data.options', ['film_id' => 12])
            ->assertJsonPath('data.current_attempt', null)
            ->assertJsonPath('data.progress', [['label' => 'Rendering the film', 'state' => 'running']]);

        $this->follower()->progress($run)->finish();
        $this->follower()->progress($run->id)->start('Mixing the sound');
        $this->follower()->text($run, 'Scene 3 of 5 is done.');
        $this->follower()->block($run, ['type' => 'image', 'src' => 'https://cdn.example.com/still.png', 'alt' => 'A still']);
        $question = $this->follower()->ask($run, 'Keep the music?', ['Yes', 'No'], id: 'music', recommended: 'Yes');

        $this->assertSame('music', $question['id']);
        $this->getJson("/api/agent-runs/{$run->id}")
            ->assertJsonPath('data.draft.text', 'Scene 3 of 5 is done.')
            ->assertJsonPath('data.draft.blocks.0.type', 'image')
            ->assertJsonPath('data.draft.blocks.1.recommended', 'yes')
            ->assertJsonPath('data.progress.1', ['label' => 'Mixing the sound', 'state' => 'running']);
        $this->getJson("/api/agent-runs/{$run->id}/attempts")->assertJsonPath('data', []);

        $this->assertTrue($this->follower()->complete(
            $run,
            'The film is ready.',
            [['type' => 'callout', 'text' => 'Exported in 4K.']],
            [['type' => 'video', 'url' => 'https://cdn.example.com/film.mp4', 'mime' => 'video/mp4', 'name' => 'film.mp4']],
        ));
        $this->assertFalse($this->follower()->complete($run, 'Again.'));

        $this->getJson("/api/agent-runs/{$run->id}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.draft', null)
            ->assertJsonPath('data.progress', [
                ['label' => 'Rendering the film', 'state' => 'done'],
                ['label' => 'Mixing the sound', 'state' => 'done'],
            ])
            ->assertJsonPath('data.reply.body', 'The film is ready.')
            ->assertJsonPath('data.reply.blocks.0', ['type' => 'markdown', 'text' => 'The film is ready.'])
            ->assertJsonPath('data.reply.blocks.1.type', 'image')
            ->assertJsonPath('data.reply.blocks.2.id', 'music')
            ->assertJsonPath('data.reply.blocks.2.answer', null)
            ->assertJsonPath('data.reply.blocks.3.type', 'callout')
            ->assertJsonPath('data.reply.attachments.0.name', 'film.mp4');

        $this->assertSame(1, Message::query()->whereNull('author_type')->count());
        Event::assertDispatchedTimes(AgentRunStarted::class, 1);
        Event::assertDispatchedTimes(AgentRunProgressed::class, 2);
        Event::assertDispatchedTimes(AgentRunCompleted::class, 1);

        $kinds = [];
        Event::assertDispatched(AgentRunUpdated::class, function (AgentRunUpdated $event) use (&$kinds) {
            $kinds[] = $event->kind;

            return true;
        });
        $this->assertSame(['started', 'progress', 'progress', 'progress', 'text', 'block', 'block', 'progress', 'settled'], $kinds);

        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['answers' => ['music' => 'no']])->assertStatus(202);
        $this->getJson("/api/agent-runs/{$run->id}")->assertJsonPath('data.reply.blocks.2.answer', ['options' => ['no'], 'text' => null]);
    }

    #[Test]
    public function a_followed_run_can_fail_with_its_step_marked_failed(): void
    {
        Event::fake([AgentRunFailed::class]);
        $dee = $this->user();

        $run = $this->follower()->start($this->conversation(), $dee, 'render', 'Rendering the film');
        $this->follower()->text($run, 'Half way.');
        $this->follower()->fail($run, 'The render farm is down.');

        $this->actingAs($dee)->getJson("/api/agent-runs/{$run->id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'The render farm is down.')
            ->assertJsonPath('data.draft', null)
            ->assertJsonPath('data.progress', [['label' => 'Rendering the film', 'state' => 'failed']]);

        $this->postJson("/api/agent-runs/{$run->id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A followed run tracks work outside the agent and cannot be retried.');
        $this->assertSame('failed', $run->fresh()->status->value);
        Event::assertDispatchedTimes(AgentRunFailed::class, 1);
    }

    #[Test]
    public function the_requester_can_cancel_a_followed_run_and_later_calls_do_nothing(): void
    {
        $dee = $this->user('Dee');
        $run = $this->follower()->start($this->conversation(), $dee, 'render', 'Rendering the film');

        $this->actingAs($this->user('Ben'))->postJson("/api/agent-runs/{$run->id}/cancel")->assertForbidden();
        $this->actingAs($dee)->postJson("/api/agent-runs/{$run->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/agent-runs/{$run->id}/retry")->assertStatus(409);

        $this->follower()->progress($run)->finish();
        $this->follower()->block($run, ['type' => 'markdown', 'text' => 'Late.']);
        $this->assertFalse($this->follower()->complete($run, 'Too late.'));

        $this->getJson("/api/agent-runs/{$run->id}")
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.draft', null)
            ->assertJsonPath('data.reply', null)
            ->assertJsonPath('data.progress', [['label' => 'Rendering the film', 'state' => 'running']]);
        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function the_events_stream_follows_a_followed_run(): void
    {
        Sleep::fake();
        $dee = $this->user();
        $run = $this->follower()->start($this->conversation(), $dee, 'render', 'Rendering the film');

        $polls = 0;
        Sleep::whenFakingSleep(function () use ($run, &$polls) {
            match (++$polls) {
                1 => $this->follower()->text($run, 'Scene 1 of 2.'),
                2 => $this->follower()->block($run, ['type' => 'markdown', 'text' => 'Scene 1 looks good.']),
                default => $this->follower()->complete($run, 'Done.'),
            };
        });

        $content = $this->actingAs($dee)->get("/api/agent-runs/{$run->id}/events")->assertOk()->streamedContent();
        preg_match_all("/event: (\\w+)\ndata: (.*)\n\n/", $content, $matches, PREG_SET_ORDER);
        $events = array_map(fn (array $match) => [$match[1], json_decode($match[2], true)], $matches);

        $this->assertSame(['started', 'progress', 'text', 'block', 'progress', 'settled'], array_column($events, 0));
        $this->assertSame('follow:render', $events[0][1]['harness']);
        $this->assertSame(['steps' => [['label' => 'Rendering the film', 'state' => 'running']]], $events[1][1]);
        $this->assertSame(['text' => 'Scene 1 of 2.'], $events[2][1]);
        $this->assertSame(['index' => 0, 'block' => ['type' => 'markdown', 'text' => 'Scene 1 looks good.']], $events[3][1]);
        $this->assertSame(['status' => 'completed', 'reply_message_id' => $run->fresh()->reply_message_id, 'error' => null], $events[5][1]);
    }

    #[Test]
    public function a_followed_run_does_not_hold_up_prompts_in_its_conversation(): void
    {
        Queue::fake();
        $dee = $this->user();
        $conversation = $this->conversation();
        $this->follower()->start($conversation, $dee, 'render');

        $id = $this->actingAs($dee)
            ->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'While it renders, a question'])
            ->json('data.id');
        (new RunAgent($id))->handle(app(RunExecutor::class));

        $this->assertSame('completed', AgentRun::findOrFail($id)->status->value);

        config(['agent-chat.concurrency' => 'reject']);
        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Another'])->assertStatus(202);
    }

    #[Test]
    public function a_followed_run_keeps_owner_access(): void
    {
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

        $dee = $this->user();
        $foreign = $this->follower()->start($this->conversation(ownerId: 2), null, 'import');

        $this->actingAs($dee)->getJson("/api/agent-runs/{$foreign->id}")->assertForbidden();
        $this->get("/api/agent-runs/{$foreign->id}/events")->assertForbidden();

        $this->expectException(AuthorizationException::class);
        $this->follower()->start($this->conversation(ownerId: 2), $dee, 'import');
    }
}
