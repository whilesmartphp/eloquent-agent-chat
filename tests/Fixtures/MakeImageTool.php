<?php

namespace Tests\Fixtures;

use Whilesmart\AgentChat\Tools\AbstractRenderTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Stands in for a host's image tool: it stores a generated file and hands it
 * to the run.
 */
class MakeImageTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'make_image';
    }

    public function description(): string
    {
        return 'Make an image.';
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $index = $this->collector()->attach([
            'type' => 'image',
            'url' => 'https://cdn.example.com/design.png',
            'mime' => 'image/png',
            'name' => 'design.png',
            'size' => 2048,
            'ref' => ['type' => 'library_asset', 'id' => 7],
        ], 'The poster');

        return "Attached image {$index}.";
    }
}
