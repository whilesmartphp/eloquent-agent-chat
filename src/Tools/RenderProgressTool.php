<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderProgressTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_progress';
    }

    public function description(): string
    {
        return 'Render progress toward targets, such as hours used against a budget. items_json is a JSON '
            .'array of objects with label, current and target, and optionally unit.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('items_json', 'A JSON array like [{"label":"Online shop","current":16,"target":60,"unit":"h"}].'),
            ParameterSpec::string('title', 'Optional title.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $items = $this->rows($arguments['items_json'] ?? null);
        $items = $items === null ? null : array_values(array_filter(
            $items,
            fn (array $item) => isset($item['label'], $item['current'], $item['target']),
        ));

        if ($items === null || $items === []) {
            return ['error' => 'items_json must be a JSON array of objects with label, current and target.'];
        }

        $this->collector()->add($this->blocks()->progress($items, $this->title($arguments)));

        return 'Rendered progress for '.count($items).' targets.';
    }
}
