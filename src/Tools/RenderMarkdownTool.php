<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderMarkdownTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_markdown';
    }

    public function description(): string
    {
        return 'Render a section of prose as Markdown. Use it inside a canvas for headings and narrative '
            .'between tables and charts.';
    }

    public function parameters(): array
    {
        return [ParameterSpec::string('text', 'The Markdown to render.')];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $text = trim((string) ($arguments['text'] ?? ''));

        if ($text === '') {
            return ['error' => 'text is required.'];
        }

        $this->collector()->add($this->blocks()->markdown($text));

        return 'Rendered a Markdown section.';
    }
}
