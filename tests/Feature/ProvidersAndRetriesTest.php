<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Whilesmart\AgentChat\Contracts\ProviderPlan;
use Whilesmart\AgentChat\Contracts\RetryPolicy;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Models\AgentRunAttempt;
use Whilesmart\Agents\ValueObjects\AgentResult;

class ProvidersAndRetriesTest extends TestCase
{
    private function planOf(array $candidates): void
    {
        $this->app->bind(ProviderPlan::class, fn () => new class($candidates) implements ProviderPlan
        {
            public function __construct(private array $candidates) {}

            public function candidates(AgentRun $run): array
            {
                return $this->candidates;
            }
        });
    }

    #[Test]
    public function the_run_falls_back_to_the_next_provider_and_keeps_every_attempt(): void
    {
        $this->planOf([
            ['provider' => 'openai', 'model' => 'gpt-image'],
            ['provider' => 'gemini', 'model' => 'gemini-image', 'temperature' => 0.2],
        ]);

        $this->harness->script(fn ($call, $input, $context, array $overrides) => $overrides['provider'] === 'openai'
            ? AgentResult::failed('Rate limited.')
            : AgentResult::success('Drawn.', usage: ['prompt_tokens' => 120, 'completion_tokens' => 30]));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Draw a poster'])
            ->json('data.id');

        $this->assertSame([
            ['provider' => 'openai', 'model' => 'gpt-image'],
            ['provider' => 'gemini', 'model' => 'gemini-image', 'temperature' => 0.2],
        ], $this->harness->overrides);

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reply.body', 'Drawn.')
            ->assertJsonPath('data.current_attempt.number', 2)
            ->assertJsonPath('data.current_attempt.provider', 'gemini');

        $this->getJson("/api/agent-runs/{$id}/attempts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.provider', 'openai')
            ->assertJsonPath('data.0.model', 'gpt-image')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.error', 'Rate limited.')
            ->assertJsonPath('data.0.retryable', true)
            ->assertJsonPath('data.0.usage', null)
            ->assertJsonPath('data.1.status', 'succeeded')
            ->assertJsonPath('data.1.usage', ['prompt_tokens' => 120, 'completion_tokens' => 30]);
    }

    #[Test]
    public function a_failure_the_retry_policy_rejects_fails_the_run_at_once(): void
    {
        config(['agent-chat.queue.tries' => 3]);
        $this->planOf([['provider' => 'openai'], ['provider' => 'gemini']]);
        $this->app->bind(RetryPolicy::class, fn () => new class implements RetryPolicy
        {
            public function retryable(AgentRun $run, AgentRunAttempt $attempt, AgentResult $result): bool
            {
                return $result->error !== 'Content policy violation.';
            }
        });

        $this->harness->script(fn () => AgentResult::failed('Content policy violation.'));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Draw something'])
            ->json('data.id');

        $this->assertCount(1, $this->harness->inputs);
        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Content policy violation.');
        $this->getJson("/api/agent-runs/{$id}/attempts")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.retryable', false);
    }

    #[Test]
    public function an_answer_cut_off_mid_stream_keeps_the_usage_it_reported(): void
    {
        $this->harness->script(fn () => new AgentResult(true, 'Half', usage: ['prompt_tokens' => 40, 'completion_tokens' => 9], finishReason: 'error'));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}/attempts")
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.finish_reason', 'error')
            ->assertJsonPath('data.0.usage', ['prompt_tokens' => 40, 'completion_tokens' => 9]);
    }

    #[Test]
    public function a_harness_that_throws_fails_the_attempt_without_showing_the_exception(): void
    {
        $this->harness->script(fn () => throw new RuntimeException('SQLSTATE password=hunter2'));

        $id = $this->actingAs($this->user())
            ->postJson("/api/conversations/{$this->conversation()->id}/agent-runs", ['body' => 'Report'])
            ->assertStatus(202)
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'The agent stopped unexpectedly.');
        $this->getJson("/api/agent-runs/{$id}/attempts")->assertJsonPath('data.0.error', 'The agent stopped unexpectedly.');
    }
}
