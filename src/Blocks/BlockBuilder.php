<?php

namespace Whilesmart\AgentChat\Blocks;

use Illuminate\Support\Str;

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
     * back as the user's next prompt, or its id under `answers` with the next
     * prompt so the question stays answered.
     *
     * The question `id` is generated when none is given and option ids come
     * from their labels, so both stay stable once the block is stored.
     * `write_in` is false, true, or the label of a free text answer.
     * `recommended` is the id (or label) of the option the agent suggests.
     *
     * @param  array<int, array{id?: string, label?: string, message?: string}|string>  $options
     * @return array<string, mixed>
     */
    public function question(
        string $prompt,
        array $options,
        ?string $id = null,
        bool $multiple = false,
        bool|string $writeIn = false,
        ?string $why = null,
        ?string $recommended = null,
    ): array {
        $normalized = [];
        $ids = [];

        foreach ($options as $option) {
            $label = trim(is_string($option) ? $option : (string) ($option['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $optionId = $this->uniqueId(
                trim(is_array($option) ? (string) ($option['id'] ?? '') : '') ?: (Str::slug($label) ?: 'option-'.(count($normalized) + 1)),
                $ids,
            );
            $ids[] = $optionId;
            $message = is_array($option) ? (string) ($option['message'] ?? $label) : $label;
            $normalized[] = ['id' => $optionId, 'label' => $label, 'message' => $message];
        }

        $id = trim((string) $id);
        $writeIn = is_string($writeIn) ? (trim($writeIn) === '' ? false : trim($writeIn)) : $writeIn;
        $recommended = trim((string) $recommended);
        $recommendedId = null;

        foreach ($normalized as $option) {
            if ($recommended !== '' && ($option['id'] === $recommended || $option['label'] === $recommended)) {
                $recommendedId = $option['id'];

                break;
            }
        }

        $why = trim((string) $why);

        return $this->compact([
            'type' => 'question',
            'id' => $id !== '' ? $id : 'q_'.Str::lower((string) Str::ulid()),
            'prompt' => $prompt,
            'options' => $normalized,
            'multiple' => $multiple,
            'write_in' => $writeIn,
            'why' => $why === '' ? null : $why,
            'recommended' => $recommendedId,
        ]);
    }

    /**
     * Build a question block from a block a host hands over, such as one
     * filed by a tool from another source. Returns null when it has no
     * prompt or no usable options.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    public function questionFrom(array $block): ?array
    {
        $prompt = trim((string) ($block['prompt'] ?? ''));
        $writeIn = $block['write_in'] ?? false;

        $question = $this->question(
            $prompt,
            is_array($block['options'] ?? null) ? $block['options'] : [],
            is_scalar($block['id'] ?? null) ? (string) $block['id'] : null,
            (bool) ($block['multiple'] ?? false),
            is_string($writeIn) ? $writeIn : (bool) $writeIn,
            is_string($block['why'] ?? null) ? $block['why'] : null,
            is_scalar($block['recommended'] ?? null) ? (string) $block['recommended'] : null,
        );

        if ($prompt === '' || $question['options'] === []) {
            return null;
        }

        return isset($block['ref']) && is_array($block['ref']) ? [...$question, 'ref' => $block['ref']] : $question;
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
     * @param  array<int, string>  $taken
     */
    private function uniqueId(string $id, array $taken): string
    {
        $candidate = $id;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$id}-{$n}";
        }

        return $candidate;
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
