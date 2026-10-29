<?php

namespace Whilesmart\AgentChat\Export;

use InvalidArgumentException;
use Whilesmart\AgentChat\Contracts\CanvasExporter;

class CanvasExporters
{
    public function has(string $format): bool
    {
        return array_key_exists($format, (array) config('agent-chat.exporters', []));
    }

    public function for(string $format): CanvasExporter
    {
        $class = config("agent-chat.exporters.{$format}");
        $exporter = is_string($class) ? app($class) : null;

        if (! $exporter instanceof CanvasExporter) {
            throw new InvalidArgumentException(
                "The agent-chat exporter for {$format} must implement ".CanvasExporter::class.'.',
            );
        }

        return $exporter;
    }
}
