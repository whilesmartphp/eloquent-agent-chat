<?php

namespace Whilesmart\AgentChat\Contracts;

/**
 * Turns a canvas (an ordered list of blocks) into a downloadable document.
 * Register one per format under `agent-chat.exporters`.
 */
interface CanvasExporter
{
    public function mimeType(): string;

    public function extension(): string;

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function export(string $title, array $blocks): string;
}
