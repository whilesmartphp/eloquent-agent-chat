<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentChat\Events\AgentRunUpdated;
use Whilesmart\AgentChat\Jobs\RunAgent;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Runs\RunExecutor;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
use Whilesmart\Conversations\Models\Message;

class StreamingTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function events(string $content): array
    {
        preg_match_all("/event: (\\w+)\ndata: (.*)\n\n/", $content, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [$match[1], json_decode($match[2], true)], $matches);
    }

    #[Test]
    public function the_draft_follows_the_text_and_blocks_while_the_agent_writes(): void
    {
        config(['agent-chat.streaming.draft_throttle_ms' => 0]);
        $seen = [];

        $this->harness->script(function (callable $call, string $input, $context) use (&$seen) {
            $run = fn () => AgentRun::findOrFail($context->get('agent_run_id'));

            $this->harness->emit(AgentStreamEvent::textDelta('Here is '));
            $seen[] = $run()->draft;
            $this->harness->emit(AgentStreamEvent::textDelta('the week.'));
            $call('render_kpi', ['items_json' => json_encode([['label' => 'Hours', 'value' => 32]])]);
            $seen[] = $run()->draft;
            $seen[] = $this->getJson("/api/agent-runs/{$run()->id}")->json('data.draft.text');

            return AgentResult::success('Here is the week.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'How did the week go?'])
            ->json('data.id');

        $attempt = AgentRun::findOrFail($id)->current_attempt_id;

        $this->assertSame(['attempt_id' => $attempt, 'text' => 'Here is ', 'blocks' => []], $seen[0]);
        $this->assertSame('Here is the week.', $seen[1]['text']);
        $this->assertSame('kpi', $seen[1]['blocks'][0]['type']);
        $this->assertSame('Here is the week.', $seen[2]);

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.draft', null)
            ->assertJsonPath('data.reply.body', 'Here is the week.')
            ->assertJsonCount(2, 'data.reply.blocks');
        $this->assertSame(1, Message::query()->whereNull('author_type')->count());
    }

    #[Test]
    public function draft_saves_are_throttled_and_broadcast_as_text_and_block_updates(): void
    {
        config(['agent-chat.broadcast.enabled' => true, 'agent-chat.streaming.draft_throttle_ms' => 60000]);
        Event::fake([AgentRunUpdated::class]);
        $seen = [];

        $this->harness->script(function (callable $call, string $input, $context) use (&$seen) {
            foreach (['One', ' two', ' three'] as $delta) {
                $this->harness->emit(AgentStreamEvent::textDelta($delta));
            }

            $seen[] = AgentRun::findOrFail($context->get('agent_run_id'))->draft['text'];
            $call('render_markdown', ['text' => 'Drawn.']);
            $seen[] = AgentRun::findOrFail($context->get('agent_run_id'))->draft;

            return AgentResult::success('One two three');
        });

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Count'])
            ->assertStatus(202);

        $this->assertSame('One', $seen[0]);
        $this->assertSame('One two three', $seen[1]['text']);
        $this->assertSame([['type' => 'markdown', 'text' => 'Drawn.']], $seen[1]['blocks']);

        $kinds = [];
        Event::assertDispatched(AgentRunUpdated::class, function (AgentRunUpdated $event) use (&$kinds) {
            $kinds[] = $event->kind;

            return true;
        });
        $this->assertSame(['started', 'text', 'text', 'progress', 'block', 'progress', 'settled'], $kinds);
    }

    #[Test]
    public function a_failed_run_clears_its_draft(): void
    {
        config(['agent-chat.streaming.draft_throttle_ms' => 0]);

        $this->harness->script(function () {
            $this->harness->emit(AgentStreamEvent::textDelta('Half an ans'));

            return AgentResult::failed('Provider timed out.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.draft', null);
    }

    #[Test]
    public function the_events_stream_sends_text_and_blocks_before_the_run_settles(): void
    {
        Queue::fake();
        config(['agent-chat.streaming.draft_throttle_ms' => 0]);
        $dee = $this->user();

        $id = $this->actingAs($dee)
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'How did the week go?'])
            ->json('data.id');

        $run = app(RunExecutor::class)->claim($id);
        $paused = null;

        $this->harness->script(function (callable $call) use (&$paused) {
            $this->harness->emit(AgentStreamEvent::textDelta('Here is the week.'));
            $call('render_kpi', ['items_json' => json_encode([['label' => 'Hours', 'value' => 32]])]);
            $paused();

            return AgentResult::success('Here is the week.');
        });

        Sleep::fake();
        $content = null;
        $paused = function () use ($id, &$content) {
            $response = $this->get("/api/agent-runs/{$id}/events");
            $content = [$response->getStatusCode(), $response->headers->get('Content-Type'), $response->streamedContent()];
        };

        config(['agent-chat.streaming.max_seconds' => 1]);
        Sleep::syncWithCarbon();
        app(RunExecutor::class)->execute($run);

        [$status, $type, $content] = $content;
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('text/event-stream', $type);
        $events = $this->events($content);
        $this->assertSame(['started', 'progress', 'text', 'block', 'timeout'], array_column($events, 0));
        $this->assertSame(['text' => 'Here is the week.'], $events[2][1]);
        $this->assertSame(0, $events[3][1]['index']);
        $this->assertSame('kpi', $events[3][1]['block']['type']);
        $this->assertSame(['status' => 'running'], $events[4][1]);

        $settled = $this->events($this->get("/api/agent-runs/{$id}/events")->streamedContent());
        $reply = AgentRun::findOrFail($id)->reply_message_id;

        $this->assertSame(['started', 'progress', 'settled'], array_column($settled, 0));
        $this->assertSame(['status' => 'completed', 'reply_message_id' => $reply, 'error' => null], $settled[2][1]);
        $this->assertNotNull($reply);
        Queue::assertPushed(RunAgent::class);
    }

    #[Test]
    public function the_events_stream_polls_until_the_run_settles(): void
    {
        Queue::fake();
        Sleep::fake();
        $dee = $this->user();

        $id = $this->actingAs($dee)
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi'])
            ->json('data.id');

        $polls = 0;
        Sleep::whenFakingSleep(function () use ($id, &$polls) {
            if (++$polls === 2) {
                (new RunAgent($id))->handle(app(RunExecutor::class));
            }
        });

        $events = $this->events($this->get("/api/agent-runs/{$id}/events")->streamedContent());

        $this->assertSame(['started', 'settled'], array_column($events, 0));
        $this->assertSame('completed', $events[1][1]['status']);
        $this->assertSame(2, $polls);
    }
}
