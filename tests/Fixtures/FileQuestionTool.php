<?php

namespace Tests\Fixtures;

use Whilesmart\AgentChat\Tools\AbstractRenderTool;
use Whilesmart\Agents\ValueObjects\ToolContext;

/**
 * Stands in for a host tool that files a question it got from elsewhere,
 * such as a planner asking which scene to cut, straight into the reply.
 */
class FileQuestionTool extends AbstractRenderTool
{
    public function name(): string
    {
        return 'file_question';
    }

    public function description(): string
    {
        return 'File a question.';
    }

    public function handle(array $arguments, ToolContext $context): string|array
    {
        $this->collector()->add([
            'type' => 'question',
            'id' => 'scene',
            'prompt' => 'Which scene should go?',
            'options' => [['id' => 's1', 'label' => 'Opening'], 'Ending'],
            'write_in' => true,
            'ref' => ['type' => 'plan', 'id' => 9],
        ]);

        return 'Filed.';
    }
}
