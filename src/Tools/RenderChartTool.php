<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderChartTool extends AbstractRenderTool
{
    public const HINTS = ['line', 'area', 'bar', 'hbar', 'pie', 'donut', 'scatter', 'treemap'];

    public function name(): string
    {
        return 'render_chart';
    }

    public function description(): string
    {
        return 'Render a chart from rows_json, a JSON array of flat objects you already have. chart_hint '
            .'picks the shape: pie or donut for parts of one total, line or area for trends over time, '
            .'bar or hbar for ranking, treemap for nested parts.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('rows_json', 'A JSON array of flat objects, e.g. [{"week":"W41","hours":30}].'),
            ParameterSpec::enum('chart_hint', 'Preferred chart type.', self::HINTS, required: false),
            ParameterSpec::string('title', 'Optional chart title.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $rows = $this->rows($arguments['rows_json'] ?? null);

        if ($rows === null) {
            return ['error' => 'rows_json must be a non-empty JSON array of objects.'];
        }

        $hint = in_array($arguments['chart_hint'] ?? null, self::HINTS, true) ? $arguments['chart_hint'] : 'bar';

        $this->collector()->add($this->blocks()->chart($hint, $rows, $this->title($arguments)));

        return "Rendered a {$hint} chart of ".count($rows).' rows.';
    }
}
