<?php

namespace Tests\Fixtures;

use Whilesmart\AgentChat\Runs\RunProgress;
use Whilesmart\AgentChat\Tools\AbstractRenderTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Stands in for a host tool that reports its own steps and shows a host
 * record as its own block type.
 */
class DrawDesignTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'draw_design';
    }

    public function description(): string
    {
        return 'Draw a design.';
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $progress = app(RunProgress::class);
        $progress->start('Rendering the design');
        $this->collector()->add(['type' => 'design_version', 'ref' => ['type' => 'design_version', 'id' => 42]]);
        $progress->finish();

        return 'Drew version 42.';
    }
}
