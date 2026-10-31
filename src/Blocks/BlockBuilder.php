<?php

namespace Whilesmart\AgentChat\Blocks;

/**
 * Builds the typed blocks a reply is made of. Block shape is authored here,
 * never by the model: tools hand raw values to these factories so clients
 * always receive the same contract.
 */
class BlockBuilder
{
    public const CORE_TYPES = ['markdown', 'table', 'kpi', 'chart', 'callout', 'timeline', 'progress', 'question', 'image', 'canvas'];

    /**
     * @return array{type: string, text: string}
     */
    public function markdown(string $text): array
    {
        return ['type' => 'markdown', 'text' => $text];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function table(array $columns, array $rows, ?string $title = null): array
    {
        return $this->compact([
            'type' => 'table',
            'title' => $title,
            'columns' => array_values($columns),
            'rows' => array_values($rows),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  Each item: {label, value, unit?, delta_percent?, trend?}.
     * @return array<string, mixed>
     */
    public function kpi(array $items, ?string $title = null): array
    {
        return $this->compact([
            'type' => 'kpi',
            'title' => $title,
            'items' => array_values($items),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function chart(string $chartHint, array $rows, ?string $title = null): array
    {
        return $this->compact([
            'type' => 'chart',
            'title' => $title,
            'chart_hint' => $chartHint,
            'rows' => array_values($rows),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function callout(string $text, string $variant = 'info', ?string $title = null): array
    {
        return $this->compact([
            'type' => 'callout',
            'variant' => in_array($variant, ['info', 'success', 'warning', 'danger'], true) ? $variant : 'info',
            'title' => $title,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  Each item: {date, title, description?}.
     * @return array<string, mixed>
     */
    public function timeline(array $items, ?string $title = null): array
    {
        return $this->compact([
            'type' => 'timeline',
            'title' => $title,
            'items' => array_values($items),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  Each item: {label, current, target, unit?}.
     * @return array<string, mixed>
     */
    public function progress(array $items, ?string $title = null): array
    {
        return $this->compact([
            'type' => 'progress',
            'title' => $title,
            'items' => array_values($items),
        ]);
    }

    /**
     * A question with selectable answers. Picking an option sends its message
     * back as the user's next prompt.
     *
     * @param  array<int, array{label?: string, message?: string}|string>  $options
     * @return array<string, mixed>
     */
    public function question(string $prompt, array $options): array
    {
        $normalized = [];

        foreach ($options as $option) {
            $label = trim(is_string($option) ? $option : (string) ($option['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $message = is_array($option) ? (string) ($option['message'] ?? $label) : $label;
            $normalized[] = ['label' => $label, 'message' => $message];
        }

        return ['type' => 'question', 'prompt' => $prompt, 'options' => $normalized];
    }

    /**
     * An image shown in the reply. `attachment` is the image's position in the
     * reply message's attachments, so the file is stored once.
     *
     * @param  array{type: string, id: mixed}|null  $ref
     * @return array<string, mixed>
     */
    public function image(?string $src, ?string $alt = null, ?int $attachment = null, ?array $ref = null): array
    {
        return $this->compact([
            'type' => 'image',
            'src' => $src,
            'alt' => $alt,
            'attachment' => $attachment,
            'ref' => $ref,
        ]);
    }

    /**
     * A titled document assembled from other blocks, shown beside the chat.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public function canvas(string $title, array $blocks): array
    {
        return ['type' => 'canvas', 'title' => $title, 'blocks' => array_values($blocks)];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function compact(array $block): array
    {
        return array_filter($block, fn ($value) => $value !== null);
    }
}
