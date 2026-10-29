<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\AgentChat\Blocks\BlockBuilder;
use Whilesmart\AgentChat\Blocks\BlockCollector;
use Whilesmart\Agents\Enums\ToolPermission;
use Whilesmart\Agents\Tools\AbstractTool;

/**
 * Base for tools that draw a block into the reply. Drawing changes no data,
 * so render tools carry the read permission and read-only harnesses may use them.
 */
abstract class AbstractRenderTool extends AbstractTool
{
    public function permission(): ToolPermission
    {
        return ToolPermission::READ;
    }

    protected function collector(): BlockCollector
    {
        return app(BlockCollector::class);
    }

    protected function blocks(): BlockBuilder
    {
        return app(BlockBuilder::class);
    }

    /**
     * Decode a JSON array argument into a list of arrays, dropping anything else.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function rows(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        $rows = array_values(array_filter($decoded, 'is_array'));

        return $rows === [] ? null : $rows;
    }

    protected function title(array $arguments): ?string
    {
        $title = trim((string) ($arguments['title'] ?? ''));

        return $title === '' ? null : $title;
    }
}
