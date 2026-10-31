<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ScriptedHarness;
use Tests\TestCase;
use Whilesmart\AgentChat\AgentChatServiceProvider;
use Whilesmart\AgentChat\Contracts\ResponseFormatter;
use Whilesmart\AgentChat\Contracts\RunInputBuilder;
use Whilesmart\AgentChat\Events\AgentRunUpdated;
use Whilesmart\AgentChat\Http\Resources\AgentRunResource;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;

class CustomizationTest extends TestCase
{
    #[Test]
    public function a_configured_resource_and_response_formatter_shape_the_response(): void
    {
        config([
            'agent-chat.resources.run' => CompactRunResource::class,
            'agent-chat.response_formatter' => BareFormatter::class,
        ]);

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi'])
            ->assertStatus(202)
            ->assertExactJson(['run' => ['state' => 'completed']]);
    }

    #[Test]
    public function a_configured_input_builder_decides_what_the_agent_reads(): void
    {
        $this->app->bind(RunInputBuilder::class, fn () => new class implements RunInputBuilder
        {
            public function build(AgentRun $run): string
            {
                return 'Workspace context. '.$run->promptMessage->body;
            }
        });

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi']);

        $this->assertSame(['Workspace context. Hi'], $this->harness->inputs);
    }

    #[Test]
    public function a_listed_harness_can_be_picked_per_run(): void
    {
        config(['agent-chat.harnesses' => ['reporter']]);
        $reporter = (new ScriptedHarness('reporter'))->script(fn () => AgentResult::success('From the reporter.'));
        Agents::registerHarness('reporter', $reporter);

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi', 'harness' => 'reporter'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.harness', 'reporter')
            ->assertJsonPath('data.reply.body', 'From the reporter.');
        $this->assertSame([], $this->harness->inputs);
    }

    #[Test]
    public function progress_labels_come_from_config(): void
    {
        config(['agent-chat.progress_labels' => ['render_*' => 'Drawing']]);

        $this->harness->script(function (callable $call) {
            $call('render_markdown', ['text' => 'x']);

            return AgentResult::success('ok');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")->assertJsonPath('data.progress', [['label' => 'Drawing', 'state' => 'done']]);
    }

    #[Test]
    public function runs_broadcast_start_progress_and_settle_when_enabled(): void
    {
        config(['agent-chat.broadcast.enabled' => true]);
        Event::fake([AgentRunUpdated::class]);

        $this->harness->script(function (callable $call) {
            $call('render_markdown', ['text' => 'x']);

            return AgentResult::success('ok');
        });

        $conversation = $this->conversation();
        $this->actingAs($this->user())->postJson("/api/conversations/{$conversation->id}/agent-runs", ['body' => 'Hi']);

        $kinds = [];
        Event::assertDispatched(AgentRunUpdated::class, function (AgentRunUpdated $event) use (&$kinds, $conversation) {
            $kinds[] = [$event->kind, $event->label];

            return $event->broadcastOn()[0]->name === "private-agent-chat.conversation.{$conversation->id}";
        });
        $this->assertSame([['started', null], ['progress', 'Putting it together'], ['block', null], ['progress', 'Putting it together'], ['settled', null]], $kinds);
    }

    #[Test]
    public function nothing_is_broadcast_by_default(): void
    {
        Event::fake([AgentRunUpdated::class]);

        $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Hi']);

        Event::assertNotDispatched(AgentRunUpdated::class);
    }

    #[Test]
    public function an_invalid_response_formatter_fails_with_a_clear_error(): void
    {
        config(['agent-chat.response_formatter' => \stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('response formatter must implement');

        $this->app->make(ResponseFormatter::class);
    }

    #[Test]
    public function an_invalid_controller_is_rejected_at_registration(): void
    {
        config(['agent-chat.controller' => \stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('controller must extend');

        (new AgentChatServiceProvider($this->app))->register();
    }
}

class CompactRunResource extends AgentRunResource
{
    public function toArray(Request $request): array
    {
        return ['state' => $this->status->value];
    }
}

class BareFormatter implements ResponseFormatter
{
    public function success(mixed $data = null, int $statusCode = 200, ?string $message = null): JsonResponse
    {
        return response()->json(['run' => $data], $statusCode);
    }

    public function failure(string $message, int $statusCode = 400, array $errors = []): JsonResponse
    {
        return response()->json(['problem' => $message], $statusCode);
    }
}
