<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderTimelineTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_timeline';
    }

    public function description(): string
    {
        return 'Render events in time order. items_json is a JSON array of objects with date and title, '
            .'and optionally description.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('items_json', 'A JSON array like [{"date":"2026-10-02","title":"Released checkout"}].'),
            ParameterSpec::string('title', 'Optional title.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $items = $this->rows($arguments['items_json'] ?? null);
        $items = $items === null ? null : array_values(array_filter(
            $items,
            fn (array $item) => isset($item['date'], $item['title']),
        ));

        if ($items === null || $items === []) {
            return ['error' => 'items_json must be a JSON array of objects with date and title.'];
        }

        $this->collector()->add($this->blocks()->timeline($items, $this->title($arguments)));

        return 'Rendered a timeline of '.count($items).' events.';
    }
}
