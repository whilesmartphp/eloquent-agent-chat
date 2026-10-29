<?php

namespace Whilesmart\AgentChat\Export;

use Whilesmart\AgentChat\Contracts\CanvasExporter;

class MarkdownCanvasExporter implements CanvasExporter
{
    public function mimeType(): string
    {
        return 'text/markdown';
    }

    public function extension(): string
    {
        return 'md';
    }

    public function export(string $title, array $blocks): string
    {
        $parts = ['# '.trim($title)];

        foreach ($blocks as $block) {
            $rendered = is_array($block) ? $this->block($block) : '';

            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        return implode("\n\n", $parts)."\n";
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function block(array $block): string
    {
        $heading = empty($block['title']) ? [] : ['## '.trim((string) $block['title'])];

        $body = match ($block['type'] ?? '') {
            'markdown' => trim((string) ($block['text'] ?? '')),
            'table' => $this->table((array) ($block['rows'] ?? []), (array) ($block['columns'] ?? [])),
            'chart' => $this->table((array) ($block['rows'] ?? []), []),
            'kpi' => $this->lines((array) ($block['items'] ?? []), fn (array $i) => '**'.($i['label'] ?? '').':** '
                .$this->cell($i['value'] ?? '').(isset($i['unit']) ? ' '.$i['unit'] : '')),
            'progress' => $this->lines((array) ($block['items'] ?? []), fn (array $i) => '**'.($i['label'] ?? '').':** '
                .$this->cell($i['current'] ?? '').' of '.$this->cell($i['target'] ?? '').(isset($i['unit']) ? ' '.$i['unit'] : '')),
            'timeline' => $this->lines((array) ($block['items'] ?? []), fn (array $i) => '**'.($i['date'] ?? '').'** '
                .($i['title'] ?? '').(empty($i['description']) ? '' : ': '.$i['description'])),
            'callout' => '> **'.ucfirst((string) ($block['variant'] ?? 'info')).':** '.trim((string) ($block['text'] ?? '')),
            default => '',
        };

        return $body === '' ? '' : implode("\n\n", [...$heading, $body]);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<int, string>  $columns
     */
    private function table(array $rows, array $columns): string
    {
        $rows = array_values(array_filter($rows, 'is_array'));

        if ($rows === []) {
            return '';
        }

        $columns = $columns !== [] ? array_values($columns) : array_keys($rows[0]);
        $lines = [
            '| '.implode(' | ', array_map(fn ($c) => ucwords(str_replace('_', ' ', (string) $c)), $columns)).' |',
            '| '.implode(' | ', array_fill(0, count($columns), '---')).' |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map(fn ($c) => $this->cell($row[$c] ?? ''), $columns)).' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function lines(array $items, callable $line): string
    {
        $items = array_values(array_filter($items, 'is_array'));

        return implode("\n", array_map(fn (array $item) => '- '.$line($item), $items));
    }

    private function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }

        return str_replace(['|', "\n"], ['\\|', ' '], (string) $value);
    }
}
