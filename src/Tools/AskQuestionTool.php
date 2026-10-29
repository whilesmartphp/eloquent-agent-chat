<?php

namespace Whilesmart\AgentChat\Tools;

use Whilesmart\Agents\ValueObjects\ParameterSpec;
use Whilesmart\Agents\ValueObjects\ToolContext;

class AskQuestionTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'ask_question';
    }

    public function description(): string
    {
        return 'Ask the user to choose instead of guessing when a needed detail is ambiguous. Pass the '
            .'question and options_json, a JSON array of short answers. Each answer becomes a button.';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('question', 'The question to ask.'),
            ParameterSpec::string('options_json', 'A JSON array of short answers, e.g. ["This week","Last week"].'),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $prompt = trim((string) ($arguments['question'] ?? ''));

        if ($prompt === '') {
            return ['error' => 'question is required.'];
        }

        $options = json_decode((string) ($arguments['options_json'] ?? ''), true);
        $block = is_array($options) ? $this->blocks()->question($prompt, $options) : null;

        if ($block === null || $block['options'] === []) {
            return ['error' => 'options_json must be a non-empty JSON array of strings.'];
        }

        $this->collector()->add($block);

        return 'Asked the user: '.$prompt;
    }
}
