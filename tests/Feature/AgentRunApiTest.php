<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentChat\Events\AgentRunCompleted;
use Whilesmart\AgentChat\Events\AgentRunFailed;
use Whilesmart\AgentChat\Events\AgentRunProgressed;
use Whilesmart\AgentChat\Events\AgentRunQueued;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Conversations\Models\Message;

class AgentRunApiTest extends TestCase
{
    #[Test]
    public function posting_a_message_runs_a_run_and_posts_a_reply_with_blocks(): void
    {
        $user = $this->user();
        $conversation = $this->conversation();

        $this->harness->script(function (callable $call) {
            $call('render_kpi', ['items_json' => json_encode([['label' => 'Hours', 'value' => 32.5, 'unit' => 'h']])]);
            $call('render_table', ['rows_json' => json_encode([['project' => 'Shop', 'hours' => 12]]), 'title' => 'By project']);

            return AgentResult::success('Here is the week.');
        });

        $response = $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'How did the week go?'])
            ->assertStatus(202)
            ->assertJsonPath('success', true);

        $run = AgentRun::findOrFail($response->json('data.id'));

        $this->assertSame('completed', $run->status->value);
        $this->assertSame([['label' => 'Putting it together', 'state' => 'done']], $run->progress);
        $this->assertSame(self::OWNER, $run->owner_type);

        $prompt = Message::findOrFail($run->prompt_message_id);
        $this->assertSame('How did the week go?', $prompt->body);
        $this->assertSame($user->getMorphClass(), $prompt->author_type);
        $this->assertEquals($user->id, $prompt->author_id);

        $this->getJson("/api/agent-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reply.body', 'Here is the week.')
            ->assertJsonPath('data.reply.blocks.0', ['type' => 'markdown', 'text' => 'Here is the week.'])
            ->assertJsonPath('data.reply.blocks.1.type', 'kpi')
            ->assertJsonPath('data.reply.blocks.2.type', 'table')
            ->assertJsonPath('data.reply.blocks.2.columns', ['project', 'hours'])
            ->assertJsonPath('data.reply.blocks.2.title', 'By project');

        $reply = Message::findOrFail($run->reply_message_id);
        $this->assertNull($reply->author_type);
        $this->assertSame($conversation->id, $reply->conversation_id);
    }

    #[Test]
    public function the_author_is_the_signed_in_user_even_when_the_request_names_another(): void
    {
        $user = $this->user('Dee');
        $other = $this->user('Ben');
        $conversation = $this->conversation();

        $response = $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/agent-runs", [
            'body' => 'Log my time',
            'author_type' => $other->getMorphClass(),
            'author_id' => $other->id,
        ])->assertStatus(202);

        $run = AgentRun::findOrFail($response->json('data.id'));

        $this->assertEquals($user->id, $run->requester_id);
        $this->assertEquals($user->id, Message::findOrFail($run->prompt_message_id)->author_id);
        $this->assertEquals($user->id, $this->harness->contexts[0]->userId());
        $this->assertSame($conversation->id, $this->harness->contexts[0]->get('conversation_id'));
    }

    #[Test]
    public function a_canvas_gathers_the_drawn_blocks_into_one_document_and_exports_as_markdown(): void
    {
        $conversation = $this->conversation();

        $this->harness->script(function (callable $call) {
            $call('open_canvas', ['title' => 'Harbor Coffee, week 44']);
            $call('render_markdown', ['text' => 'Checkout shipped.']);
            $call('render_chart', ['rows_json' => json_encode([['day' => 'Mon', 'hours' => 4]]), 'chart_hint' => 'line']);

            return AgentResult::success('The report is in the canvas.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Write the Harbor report'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonCount(2, 'data.reply.blocks')
            ->assertJsonPath('data.reply.blocks.1.type', 'canvas')
            ->assertJsonPath('data.reply.blocks.1.title', 'Harbor Coffee, week 44')
            ->assertJsonPath('data.reply.blocks.1.blocks.1.chart_hint', 'line');

        $export = $this->get("/api/agent-runs/{$id}/export?format=md")->assertOk();

        $this->assertStringStartsWith('text/markdown', $export->headers->get('Content-Type'));
        $this->assertStringContainsString('harbor-coffee-week-44.md', $export->headers->get('Content-Disposition'));
        $this->assertSame(
            "# Harbor Coffee, week 44\n\nCheckout shipped.\n\n| Day | Hours |\n| --- | --- |\n| Mon | 4 |\n",
            $export->getContent(),
        );

        $this->get("/api/agent-runs/{$id}/export?format=docx")->assertStatus(422);
    }

    #[Test]
    public function export_is_not_found_when_the_run_drew_no_canvas(): void
    {
        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi'])
            ->json('data.id');

        $this->get("/api/agent-runs/{$id}/export")->assertNotFound();
    }

    #[Test]
    public function a_follow_up_sees_the_conversation_and_can_update_the_earlier_canvas(): void
    {
        $user = $this->user();
        $conversation = $this->conversation();

        $this->harness->script(function (callable $call) {
            $call('open_canvas', ['title' => 'Week 44']);
            $call('render_markdown', ['text' => 'First draft.']);
            $call('render_table', ['rows_json' => json_encode([['project' => 'Shop', 'hours' => 12]])]);

            return AgentResult::success('Drafted.');
        });

        $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Draft the report']);

        $this->harness->script(function (callable $call) {
            $previous = $call('update_canvas', []);
            $call('render_markdown', ['text' => 'Shorter draft.']);

            $kept = str_contains($previous, 'First draft.') && str_contains($previous, '"project":"Shop","hours":12');

            return AgentResult::success($kept ? 'Shortened.' : 'Lost the old draft.');
        });

        $id = $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Make it shorter'])
            ->json('data.id');

        $input = $this->harness->inputs[1];
        $this->assertStringContainsString("Conversation so far:\nUser: Draft the report\nAssistant: Drafted.", $input);
        $this->assertStringContainsString('A canvas titled "Week 44" exists', $input);
        $this->assertStringEndsWith('User: Make it shorter', $input);

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.reply.body', 'Shortened.')
            ->assertJsonPath('data.reply.blocks.1.title', 'Week 44')
            ->assertJsonPath('data.reply.blocks.1.blocks.0.text', 'Shorter draft.');
    }

    #[Test]
    public function a_render_tool_rejects_bad_input_without_drawing_anything(): void
    {
        $this->harness->script(function (callable $call) {
            $call('render_table', ['rows_json' => 'not json']);
            $call('ask_question', ['question' => 'Which week?', 'options_json' => '[]']);

            return AgentResult::success('Could not draw that.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Table please'])
            ->json('data.id');

        $this->assertSame(
            [
                ['error' => 'rows_json must be a non-empty JSON array of objects.'],
                ['error' => 'options_json must be a non-empty JSON array of strings.'],
            ],
            $this->harness->toolResults,
        );

        $this->getJson("/api/agent-runs/{$id}")->assertJsonCount(1, 'data.reply.blocks');
    }

    #[Test]
    public function a_question_offers_its_answers_as_messages_to_send_back(): void
    {
        $this->harness->script(function (callable $call) {
            $call('ask_question', ['question' => 'Which week?', 'options_json' => json_encode(['This week', 'Last week'])]);

            return AgentResult::success('');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.reply.blocks', [[
                'type' => 'question',
                'prompt' => 'Which week?',
                'options' => [
                    ['label' => 'This week', 'message' => 'This week'],
                    ['label' => 'Last week', 'message' => 'Last week'],
                ],
            ]]);
    }

    #[Test]
    public function a_failed_run_keeps_the_error_and_can_be_retried(): void
    {
        Event::fake([AgentRunQueued::class, AgentRunCompleted::class, AgentRunFailed::class]);

        $this->harness->script(fn () => AgentResult::failed('Provider timed out.'));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Provider timed out.')
            ->assertJsonPath('data.reply', null);

        Event::assertDispatched(AgentRunFailed::class);
        Event::assertNotDispatched(AgentRunCompleted::class);

        $this->harness->script(fn () => AgentResult::success('Second time lucky.'));

        $this->postJson("/api/agent-runs/{$id}/retry")->assertStatus(202);

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.reply.body', 'Second time lucky.');

        Event::assertDispatchedTimes(AgentRunQueued::class, 2);
        Event::assertDispatched(AgentRunCompleted::class);
        $this->assertSame(1, Message::query()->whereNotNull('author_type')->count());
    }

    #[Test]
    public function only_a_failed_run_can_be_retried(): void
    {
        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi'])
            ->json('data.id');

        $this->postJson("/api/agent-runs/{$id}/retry")->assertStatus(409)->assertJsonPath('success', false);
    }

    #[Test]
    public function progress_steps_are_recorded_once_per_label_and_announced(): void
    {
        Event::fake([AgentRunProgressed::class]);

        $this->harness->script(function (callable $call) {
            $call('render_markdown', ['text' => 'a']);
            $call('render_markdown', ['text' => 'b']);
            $call('ask_question', ['question' => 'More?', 'options_json' => '["Yes"]']);

            return AgentResult::success('ok');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")->assertJsonPath('data.progress', [['label' => 'Putting it together', 'state' => 'done'], ['label' => 'Preparing a question', 'state' => 'done']]);
        Event::assertDispatchedTimes(AgentRunProgressed::class, 2);
    }

    #[Test]
    public function validation_rejects_an_empty_body_and_an_unlisted_harness(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($this->user());

        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", [])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Hi', 'harness' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('harness');

        $this->assertSame(0, AgentRun::count());
        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function index_lists_runs_newest_first_and_filters_by_conversation_and_status(): void
    {
        $first = $this->conversation();
        $second = $this->conversation();
        $this->actingAs($this->user());

        $this->postJson("/api/conversations/{$first->id}/agent-runs", ['body' => 'One']);
        $this->postJson("/api/conversations/{$second->id}/agent-runs", ['body' => 'Two']);
        AgentRun::factory()->failed()->create();

        $this->getJson('/api/agent-runs')->assertOk()->assertJsonCount(3, 'data.data');
        $this->getJson("/api/agent-runs?conversation_id={$first->id}")
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.conversation_id', $first->id);
        $this->getJson('/api/agent-runs?status=failed')->assertJsonCount(1, 'data.data');
    }
}
