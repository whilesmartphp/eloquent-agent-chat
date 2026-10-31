<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\AgentChat\Runs\RunProgress;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;

class ProgressStepsTest extends TestCase
{
    #[Test]
    public function the_step_running_when_an_attempt_fails_is_marked_failed(): void
    {
        $this->harness->script(function (callable $call) {
            $call('render_markdown', ['text' => 'Partial.']);
            $this->harness->emit(AgentStreamEvent::toolCall('search_orders'));

            return AgentResult::failed('Provider timed out.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Find my orders'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.progress', [
                ['label' => 'Putting it together', 'state' => 'done'],
                ['label' => 'Searching', 'state' => 'failed', 'detail' => 'Provider timed out.'],
            ]);
    }

    #[Test]
    public function steps_still_running_when_the_answer_arrives_are_marked_done(): void
    {
        $this->harness->script(function () {
            $this->harness->emit(AgentStreamEvent::toolCall('search_orders'));
            app(RunProgress::class)->start('Checking stock');

            return AgentResult::success('Two orders.');
        });

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Find my orders'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', [
                ['label' => 'Searching', 'state' => 'done'],
                ['label' => 'Checking stock', 'state' => 'done'],
            ]);
    }

    #[Test]
    public function a_tool_can_fail_its_own_step_with_a_reason(): void
    {
        $progress = new RunProgress;
        $progress->start('Rendering the design');
        $progress->fail(detail: 'The template is missing.');
        $progress->fail('Uploading', 'No disk.');
        $progress->start('Uploading');

        $this->assertSame([
            ['label' => 'Rendering the design', 'state' => 'failed', 'detail' => 'The template is missing.'],
            ['label' => 'Uploading', 'state' => 'running'],
        ], $progress->steps());
    }
}
