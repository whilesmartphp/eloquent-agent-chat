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
            .'question and options_json, a JSON array of short answers. Each answer becomes a button. '
            .'The user\'s choice comes back with their next message under "Answers to your questions".';
    }

    public function parameters(): array
    {
        return [
            ParameterSpec::string('question', 'The question to ask.'),
            ParameterSpec::string('options_json', 'A JSON array of short answers, e.g. ["This week","Last week"], or of objects {"id","label"}.'),
            ParameterSpec::string('id', 'A short id for the question, e.g. "week". Generated when empty.', required: false),
            ParameterSpec::boolean('multiple', 'True when the user may pick more than one answer.', required: false),
            ParameterSpec::string('write_in', 'The label of a box for an answer in their own words, e.g. "Something else". Empty for none.', required: false),
            ParameterSpec::string('why', 'One short sentence on why you need to know.', required: false),
            ParameterSpec::string('recommended', 'The answer you suggest, by id or label.', required: false),
        ];
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $prompt = trim((string) ($arguments['question'] ?? ''));

        if ($prompt === '') {
            return ['error' => 'question is required.'];
        }

        $options = json_decode((string) ($arguments['options_json'] ?? ''), true);
        $block = is_array($options) ? $this->blocks()->question(
            $prompt,
            $options,
            is_scalar($arguments['id'] ?? null) ? (string) $arguments['id'] : null,
            filter_var($arguments['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN),
            is_string($arguments['write_in'] ?? null) ? $arguments['write_in'] : false,
            is_string($arguments['why'] ?? null) ? $arguments['why'] : null,
            is_scalar($arguments['recommended'] ?? null) ? (string) $arguments['recommended'] : null,
        ) : null;

        if ($block === null || $block['options'] === []) {
            return ['error' => 'options_json must be a non-empty JSON array of strings.'];
        }

        $this->collector()->add($block);

        return 'Asked the user: '.$prompt.' (question id '.$block['id'].')';
    }
}
