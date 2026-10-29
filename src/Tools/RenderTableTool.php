<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class RenderTableTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'render_table';
    }

    public function description(): string
    {
        return 'Render a table from rows_json, a JSON array of flat objects. Columns default to the keys '
            .'of the first row; pass columns_json to choose and order them.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('rows_json', 'A JSON array of flat objects, e.g. [{"project":"Shop","hours":12}].'),
            ParameterSpec::string('columns_json', 'Optional JSON array of column keys in display order.', required: false),
            ParameterSpec::string('title', 'Optional table title.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $rows = $this->rows($arguments['rows_json'] ?? null);

        if ($rows === null) {
            return ['error' => 'rows_json must be a non-empty JSON array of objects.'];
        }

        $columns = json_decode((string) ($arguments['columns_json'] ?? ''), true);
        $columns = is_array($columns) && $columns !== []
            ? array_values(array_map('strval', $columns))
            : array_keys($rows[0]);

        $this->collector()->add($this->blocks()->table($columns, $rows, $this->title($arguments)));

        return 'Rendered a table with '.count($rows).' rows.';
    }
}
