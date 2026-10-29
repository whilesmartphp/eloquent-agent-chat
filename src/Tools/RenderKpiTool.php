<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderKpiTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_kpi';
    }

    public function description(): string
    {
        return 'Render a row of headline numbers. items_json is a JSON array of objects with label and value, '
            .'and optionally unit, delta_percent and trend (up, down or flat).';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('items_json', 'A JSON array like [{"label":"Hours","value":32.5,"unit":"h"}].'),
            ParameterSpec::string('title', 'Optional title for the row.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $items = $this->rows($arguments['items_json'] ?? null);
        $items = $items === null ? null : array_values(array_filter(
            $items,
            fn (array $item) => isset($item['label'], $item['value']),
        ));

        if ($items === null || $items === []) {
            return ['error' => 'items_json must be a JSON array of objects with label and value.'];
        }

        $this->collector()->add($this->blocks()->kpi($items, $this->title($arguments)));

        return 'Rendered '.count($items).' headline numbers.';
    }
}
