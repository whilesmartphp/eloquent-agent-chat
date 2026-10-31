<?php

namespace Tests\Fixtures;

use Closure;
use Whilesmart\Agents\Contracts\StreamingHarness;
use Whilesmart\Agents\Registries\ToolRegistry;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Stands in for a model: each run follows the script the test set, calling the
 * real registered tools and emitting the same stream events an engine would.
 */
class ScriptedHarness implements StreamingHarness
{
    /** @var array<int, string> */
    public array $inputs = [];

    /** @var array<int, ToolContext> */
    public array $contexts = [];

    /** @var array<int, mixed> */
    public array $toolResults = [];

    /** @var array<int, array<int, mixed>> */
    public array $media = [];

    /** @var array<int, array<string, mixed>> */
    public array $overrides = [];

    private Closure $script;

    public function __construct(private string $harnessName = 'assistant')
    {
        $this->script = fn () => AgentResult::success('Done.');
    }

    /**
     * @param  Closure(callable(string, array<string, mixed>): mixed, string, ToolContext, array<string, mixed>): AgentResult  $script
     */
    public function script(Closure $script): static
    {
        $this->script = $script;

        return $this;
    }

    public function name(): string
    {
        return $this->harnessName;
    }

    public function systemPrompt(?ToolContext $context = null): string
    {
        return 'Scripted.';
    }

    public function toolNames(): array
    {
        return [];
    }

    public function allowedPermissions(): array
    {
        return [];
    }

    public function provider(): ?string
    {
        return null;
    }

    public function model(): ?string
    {
        return null;
    }

    public function temperature(): ?float
    {
        return null;
    }

    public function maxSteps(): ?int
    {
        return null;
    }

    public function run(string $input, ToolContext $context, array $media = [], array $overrides = []): AgentResult
    {
        return $this->stream($input, $context, fn () => null, $media, $overrides);
    }

    public function stream(string $input, ToolContext $context, callable $onEvent, array $media = [], array $overrides = []): AgentResult
    {
        $this->inputs[] = $input;
        $this->contexts[] = $context;
        $this->media[] = $media;
        $this->overrides[] = $overrides;

        $call = function (string $tool, array $arguments = []) use ($onEvent, $context) {
            $onEvent(AgentStreamEvent::toolCall($tool, $arguments));
            $result = app(ToolRegistry::class)->resolve($tool)->handle($arguments, $context);
            $onEvent(AgentStreamEvent::toolResult($tool));
            $this->toolResults[] = $result;

            return $result;
        };

        return ($this->script)($call, $input, $context, $overrides);
    }
}
