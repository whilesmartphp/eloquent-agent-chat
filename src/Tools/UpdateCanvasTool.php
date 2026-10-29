<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\AgentChat\Turns\ConversationCanvas;
use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Edits the latest canvas in the conversation. It opens a new canvas for this
 * turn and returns the previous sections so the agent rebuilds them with the
 * change applied, instead of starting from a blank page.
 */
class UpdateCanvasTool extends AbstractRenderTool
{
    public function __construct(private ConversationCanvas $canvases) {}

    public function name(): string
    {
        return 'update_canvas';
    }

    public function description(): string
    {
        return 'Change a canvas document made earlier in this conversation. Use it instead of open_canvas when '
            .'the user asks to edit, extend or fix a previous report. It returns the previous sections; '
            .'render again every section you keep, with the change applied.';
    }

    public function parameters(): array
    {
        return [ParameterSpec::string('title', 'The new title. Defaults to the previous one.', required: false)];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $conversationId = $context->get('conversation_id');

        if ($conversationId === null) {
            return ['error' => 'No conversation in context.'];
        }

        $canvas = $this->canvases->latest($conversationId);

        if ($canvas === null) {
            return ['error' => 'There is no earlier canvas to change. Use open_canvas instead.'];
        }

        $title = $this->title($arguments) ?? (string) ($canvas['title'] ?? 'Report');
        $this->collector()->openCanvas($title);

        return "Updating \"{$title}\". Its previous sections follow. Render again every section you keep, "
            ."applying the user's change.\n\n".$this->describe($canvas);
    }

    /**
     * @param  array<string, mixed>  $canvas
     */
    private function describe(array $canvas): string
    {
        $lines = [];

        foreach (array_values((array) ($canvas['blocks'] ?? [])) as $i => $block) {
            if (! is_array($block)) {
                continue;
            }

            $n = $i + 1;
            $title = isset($block['title']) ? " \"{$block['title']}\"" : '';
            $lines[] = match ($block['type'] ?? null) {
                'markdown' => "{$n}. Markdown:\n".(string) ($block['text'] ?? ''),
                'table' => "{$n}. Table{$title} with columns ".implode(', ', (array) ($block['columns'] ?? []))
                    .', rows: '.json_encode($block['rows'] ?? []),
                'chart' => "{$n}. ".($block['chart_hint'] ?? 'bar')." chart{$title}, rows: ".json_encode($block['rows'] ?? []),
                'kpi' => "{$n}. Headline numbers{$title}: ".json_encode($block['items'] ?? []),
                default => "{$n}. ".ucfirst((string) ($block['type'] ?? 'section')).$title.': '.json_encode($block),
            };
        }

        return $lines === [] ? '(The previous canvas was empty.)' : implode("\n\n", $lines);
    }
}
