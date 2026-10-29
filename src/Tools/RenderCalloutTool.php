<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderCalloutTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_callout';
    }

    public function description(): string
    {
        return 'Render a highlighted note: an insight, a warning or a risk the user should not miss.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('text', 'The note.'),
            ParameterSpec::enum('variant', 'Tone of the note.', ['info', 'success', 'warning', 'danger'], required: false),
            ParameterSpec::string('title', 'Optional short title.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $text = trim((string) ($arguments['text'] ?? ''));

        if ($text === '') {
            return ['error' => 'text is required.'];
        }

        $this->collector()->add($this->blocks()->callout(
            $text,
            (string) ($arguments['variant'] ?? 'info'),
            $this->title($arguments),
        ));

        return 'Rendered a callout.';
    }
}
