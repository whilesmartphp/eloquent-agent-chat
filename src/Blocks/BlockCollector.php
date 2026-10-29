<?php

namespace Whilesmart\AgentChat\Blocks;

/**
 * Collects the blocks render tools emit during one agent run. A fresh
 * collector is bound for each turn so tools never leak blocks across turns.
 */
class BlockCollector
{
    /** @var array<int, array<string, mixed>> */
    private array $blocks = [];

    private ?string $canvasTitle = null;

    /**
     * @param  array<string, mixed>  $block
     */
    public function add(array $block): void
    {
        $this->blocks[] = $block;
    }

    public function openCanvas(string $title): void
    {
        $this->canvasTitle = $title !== '' ? $title : 'Report';
    }

    public function canvasTitle(): ?string
    {
        return $this->canvasTitle;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->blocks;
    }
}
