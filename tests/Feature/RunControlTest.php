<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\DrawDesignTool;
use Tests\TestCase;
use Whilesmart\AgentChat\Blocks\BlockCollector;
use Whilesmart\AgentChat\Events\AgentRunCancelled;
use Whilesmart\AgentChat\Jobs\RunAgent;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Runs\RunExecutor;
use Whilesmart\AgentChat\Runs\RunProgress;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Conversations\Enums\MessageDirection;
use Whilesmart\Conversations\Models\Message;

class RunControlTest extends TestCase
{
    private function work(int|string $id): void
    {
        (new RunAgent($id))->handle(app(RunExecutor::class));
    }

    #[Test]
    public function a_held_run_waits_until_the_requester_releases_it(): void
    {
        $dee = $this->user('Dee');

        $id = $this->actingAs($dee)
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Use the uploads', 'hold' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'held')
            ->json('data.id');

        $this->assertSame([], $this->harness->inputs);

        $this->actingAs($this->user('Ben'))->postJson("/api/agent-runs/{$id}/release")->assertForbidden();

        $this->actingAs($dee)->postJson("/api/agent-runs/{$id}/release")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/agent-runs/{$id}/release")->assertStatus(409);

        $this->assertCount(1, $this->harness->inputs);
    }

    #[Test]
    public function a_queued_run_cancelled_by_the_requester_never_runs(): void
    {
        Queue::fake();
        Event::fake([AgentRunCancelled::class]);
        $dee = $this->user('Dee');

        $id = $this->actingAs($dee)
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->actingAs($this->user('Ben'))->postJson("/api/agent-runs/{$id}/cancel")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the person who asked can cancel this run.');

        $this->actingAs($dee)->postJson("/api/agent-runs/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/agent-runs/{$id}/cancel")->assertStatus(409);
        $this->postJson("/api/agent-runs/{$id}/retry")->assertStatus(409);

        $this->work($id);

        $this->assertSame([], $this->harness->inputs);
        Event::assertDispatchedTimes(AgentRunCancelled::class, 1);
    }

    #[Test]
    public function an_answer_that_finishes_after_cancel_is_discarded(): void
    {
        Queue::fake();
        $dee = $this->user('Dee');

        $id = $this->actingAs($dee)
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->harness->script(function () use ($dee, $id) {
            $this->actingAs($dee)->postJson("/api/agent-runs/{$id}/cancel")->assertOk();

            return AgentResult::success('Too late.');
        });

        $this->work($id);

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.reply', null);
        $this->getJson("/api/agent-runs/{$id}/attempts")->assertJsonPath('data.0.status', 'abandoned');
        $this->assertSame(0, Message::query()->where('direction', MessageDirection::Outbound->value)->count());
    }

    #[Test]
    public function under_the_queue_policy_a_prompt_waits_for_the_earlier_run_in_its_conversation(): void
    {
        Queue::fake();
        $conversation = $this->conversation();
        $this->actingAs($this->user());

        $first = $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'First'])->json('data.id');
        $second = $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Second'])->json('data.id');
        $elsewhere = $this->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Elsewhere'])->json('data.id');

        $this->work($second);
        $this->assertSame('queued', AgentRun::find($second)->status->value);
        $this->assertSame([], $this->harness->inputs);

        $this->work($elsewhere);
        $this->work($first);

        Queue::assertPushed(RunAgent::class, fn (RunAgent $job) => (string) $job->runId === (string) $second);
        Queue::assertPushed(RunAgent::class, 4);

        $this->work($second);

        $this->assertSame('completed', AgentRun::find($second)->status->value);
        $this->assertStringEndsWith('User: Elsewhere', $this->harness->inputs[0]);
        $this->assertStringEndsWith('User: First', $this->harness->inputs[1]);
        $this->assertStringEndsWith('User: Second', $this->harness->inputs[2]);
    }

    #[Test]
    public function under_the_reject_policy_a_prompt_is_refused_while_another_is_unfinished(): void
    {
        Queue::fake();
        config(['agent-chat.concurrency' => 'reject']);
        $conversation = $this->conversation();
        $this->actingAs($this->user());

        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'First'])->assertStatus(202);
        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Second'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Wait for the current answer to finish before asking again.');

        $this->assertSame(1, Message::count());
    }

    #[Test]
    public function under_the_parallel_policy_runs_do_not_wait(): void
    {
        Queue::fake();
        config(['agent-chat.concurrency' => 'parallel']);
        $conversation = $this->conversation();
        $this->actingAs($this->user());

        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'First']);
        $second = $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Second'])->json('data.id');

        $this->work($second);

        $this->assertSame('completed', AgentRun::find($second)->status->value);
    }

    #[Test]
    public function language_and_options_reach_the_tools(): void
    {
        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", [
                'body' => 'Combien ce mois-ci ?',
                'language' => 'fr',
                'options' => ['format_hint' => 'table'],
            ])
            ->assertJsonPath('data.language', 'fr')
            ->assertJsonPath('data.options', ['format_hint' => 'table'])
            ->json('data.id');

        $context = $this->harness->contexts[0];
        $this->assertSame('fr', $context->locale);
        $this->assertSame(['format_hint' => 'table'], $context->get('options'));
        $this->assertSame($id, $context->get('agent_run_id'));

        $this->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi', 'language' => 'fr; drop'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('language');
    }

    #[Test]
    public function history_names_each_speaker_by_where_the_message_came_from(): void
    {
        $conversation = $this->conversation();
        $user = $this->user();

        $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Summarise the thread']);
        $conversation->messages()->create(['direction' => MessageDirection::Inbound, 'body' => 'Is my order shipped?']);
        $conversation->messages()->create(['direction' => MessageDirection::Outbound, 'body' => 'Checking now.']);

        $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'What did they ask?']);

        $this->assertStringContainsString(
            "Conversation so far:\nUser: Summarise the thread\nAssistant: Done.\nContact: Is my order shipped?\nTeam: Checking now.",
            $this->harness->inputs[1],
        );
    }

    #[Test]
    public function a_tool_can_report_its_own_steps_and_add_a_registered_block_type(): void
    {
        config(['agent-chat.block_types' => ['design_version']]);
        Agents::registerTool(new DrawDesignTool);

        $this->harness->script(function (callable $call) {
            $call('draw_design');

            return AgentResult::success('Version 42 is ready.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Design a flyer'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.progress', [['label' => 'Rendering the design', 'state' => 'done']])
            ->assertJsonPath('data.reply.blocks.1', ['type' => 'design_version', 'ref' => ['type' => 'design_version', 'id' => 42]]);
    }

    #[Test]
    public function an_unregistered_block_type_is_refused_and_progress_outside_a_run_does_nothing(): void
    {
        $progress = app(RunProgress::class);
        $progress->start('Idle');
        $progress->finish();
        $this->assertSame([['label' => 'Idle', 'state' => 'done']], $progress->steps());
        $this->assertSame([], app(RunProgress::class)->steps());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Block type "design_version" is not registered');

        (new BlockCollector)->add(['type' => 'design_version']);
    }
}
