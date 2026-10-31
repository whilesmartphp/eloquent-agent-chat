<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class OpenCanvasTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'open_canvas';
    }

    public function description(): string
    {
        return 'Start a canvas document for a report or any answer with several sections. Call it first with '
            .'a title, then call the render tools in the order the content should appear. Everything rendered '
            .'afterwards in this answer becomes one document.';
    }

    public function parameters(): array
    {
        return [ParameterSpec::string('title', 'The document title, e.g. "Harbor Coffee, week 44".')];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $title = trim((string) ($arguments['title'] ?? '')) ?: 'Report';
        $this->collector()->openCanvas($title);

        return "Canvas \"{$title}\" opened. Build it with the render tools in reading order.";
    }
}
