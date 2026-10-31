<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\FileQuestionTool;
use Tests\Fixtures\User;
use Tests\TestCase;
use Whilesmart\Agents\Facades\Agents;
use Whilesmart\Agents\ValueObjects\AgentResult;
use Whilesmart\Conversations\Models\Conversation;
use Whilesmart\Conversations\Models\Message;

class QuestionsTest extends TestCase
{
    private User $dee;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dee = $this->user();
        $this->chat = $this->conversation();
    }

    /**
     * Post a prompt whose reply asks two questions, and return the run id.
     */
    private function askAboutTheReport(): int
    {
        $this->harness->script(function (callable $call) {
            $call('ask_question', [
                'id' => 'week',
                'question' => 'Which week?',
                'options_json' => json_encode(['This week', 'Last week']),
                'write_in' => 'Another week',
                'why' => 'The report covers one week.',
                'recommended' => 'This week',
            ]);
            $call('ask_question', [
                'id' => 'projects',
                'question' => 'Which projects?',
                'options_json' => json_encode([['id' => 'shop', 'label' => 'Shop'], ['id' => 'harbor', 'label' => 'Harbor']]),
                'multiple' => true,
            ]);

            return AgentResult::success('A couple of questions first.');
        });

        $id = $this->actingAs($this->dee)
            ->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['body' => 'Write the report'])
            ->json('data.id');

        $this->harness->script(fn () => AgentResult::success('Done.'));

        return $id;
    }

    #[Test]
    public function ask_question_draws_a_question_with_ids_and_its_settings(): void
    {
        $id = $this->askAboutTheReport();

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.reply.blocks.1', [
                'type' => 'question',
                'id' => 'week',
                'prompt' => 'Which week?',
                'options' => [
                    ['id' => 'this-week', 'label' => 'This week', 'message' => 'This week'],
                    ['id' => 'last-week', 'label' => 'Last week', 'message' => 'Last week'],
                ],
                'multiple' => false,
                'write_in' => 'Another week',
                'why' => 'The report covers one week.',
                'recommended' => 'this-week',
                'answer' => null,
                'answered_by_message_id' => null,
            ])
            ->assertJsonPath('data.reply.blocks.2.id', 'projects')
            ->assertJsonPath('data.reply.blocks.2.multiple', true)
            ->assertJsonPath('data.reply.blocks.2.write_in', false)
            ->assertJsonPath('data.reply.blocks.2.options.1', ['id' => 'harbor', 'label' => 'Harbor', 'message' => 'Harbor']);

        $this->assertSame('Asked the user: Which week? (question id week)', $this->harness->toolResults[0]);
    }

    #[Test]
    public function a_question_without_an_id_gets_one_that_stays_the_same(): void
    {
        $this->harness->script(function (callable $call) {
            $call('ask_question', ['question' => 'Which week?', 'options_json' => '["This week","This week!"]']);

            return AgentResult::success('');
        });

        $id = $this->actingAs($this->dee)
            ->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['body' => 'Report'])
            ->json('data.id');

        $first = $this->getJson("/api/agent-runs/{$id}")->json('data.reply.blocks.0');
        $second = $this->getJson("/api/agent-runs/{$id}")->json('data.reply.blocks.0');

        $this->assertMatchesRegularExpression('/^q_[0-9a-z]{26}$/', $first['id']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(['this-week', 'this-week-2'], array_column($first['options'], 'id'));
    }

    #[Test]
    public function a_host_tool_can_file_a_question_block_directly(): void
    {
        Agents::registerTool(new FileQuestionTool);

        $this->harness->script(function (callable $call) {
            $call('file_question');

            return AgentResult::success('The planner has a question.');
        });

        $id = $this->actingAs($this->dee)
            ->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['body' => 'Plan the film'])
            ->json('data.id');

        $this->getJson("/api/agent-runs/{$id}")
            ->assertJsonPath('data.reply.blocks.1.id', 'scene')
            ->assertJsonPath('data.reply.blocks.1.options', [
                ['id' => 's1', 'label' => 'Opening', 'message' => 'Opening'],
                ['id' => 'ending', 'label' => 'Ending', 'message' => 'Ending'],
            ])
            ->assertJsonPath('data.reply.blocks.1.write_in', true)
            ->assertJsonPath('data.reply.blocks.1.ref', ['type' => 'plan', 'id' => 9]);

        $this->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['answers' => ['scene' => 'Cut the middle instead']])
            ->assertStatus(202);
    }

    #[Test]
    public function answers_are_stored_on_the_prompt_and_shown_with_each_question(): void
    {
        $asked = $this->askAboutTheReport();

        $response = $this->postJson("/api/conversations/{$this->chat->id}/agent-runs", [
            'body' => 'Here you go',
            'answers' => ['week' => 'last-week', 'projects' => ['shop', 'harbor']],
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.answers', ['week' => 'last-week', 'projects' => ['shop', 'harbor']]);

        $prompt = Message::findOrFail($response->json('data.prompt_message_id'));
        $askedReply = $this->getJson("/api/agent-runs/{$asked}")->json('data.reply.id');

        $this->assertSame(['week' => 'last-week', 'projects' => ['shop', 'harbor']], $prompt->metadata['answers']);
        $this->assertEquals($askedReply, $prompt->metadata['answers_to_message_id']);

        $this->getJson("/api/agent-runs/{$asked}")
            ->assertJsonPath('data.reply.blocks.1.answer', ['options' => ['last-week'], 'text' => null])
            ->assertJsonPath('data.reply.blocks.1.answered_by_message_id', $prompt->id)
            ->assertJsonPath('data.reply.blocks.2.answer', ['options' => ['shop', 'harbor'], 'text' => null])
            ->assertJsonPath('data.reply.blocks.2.answered_by_message_id', $prompt->id);

        $this->assertArrayNotHasKey('answer', Message::findOrFail($askedReply)->metadata['blocks'][1]);
    }

    #[Test]
    public function a_later_answer_does_not_replace_the_one_that_settled_the_question(): void
    {
        $asked = $this->askAboutTheReport();

        $first = $this->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['answers' => ['week' => 'Week 40']])
            ->json('data.prompt_message_id');
        $this->getJson("/api/agent-runs/{$asked}")->assertJsonPath('data.reply.blocks.2.answer', null);

        $second = $this->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['answers' => ['week' => 'this-week', 'projects' => 'shop']])
            ->assertStatus(202)
            ->json('data.prompt_message_id');

        $this->getJson("/api/agent-runs/{$asked}")
            ->assertJsonPath('data.reply.blocks.1.answer', ['options' => [], 'text' => 'Week 40'])
            ->assertJsonPath('data.reply.blocks.1.answered_by_message_id', $first)
            ->assertJsonPath('data.reply.blocks.2.answer', ['options' => ['shop'], 'text' => null])
            ->assertJsonPath('data.reply.blocks.2.answered_by_message_id', $second);
    }

    #[Test]
    public function answers_that_do_not_fit_the_questions_are_refused(): void
    {
        $this->actingAs($this->dee)
            ->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['answers' => ['week' => 'this-week']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');

        $this->askAboutTheReport();
        $url = "/api/conversations/{$this->chat->id}/agent-runs";

        $this->postJson($url, ['answers' => ['month' => 'this-week']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers.month' => 'There is no question with this id in the latest reply.']);
        $this->postJson($url, ['answers' => ['week' => ['this-week', 'last-week']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.week');
        $this->postJson($url, ['answers' => ['projects' => 'Everything']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.projects');
        $this->postJson($url, ['answers' => ['projects' => ['shop', 'admin']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.projects');

        $this->assertSame(2, Message::query()->count());
    }

    #[Test]
    public function the_agent_reads_each_question_with_the_answer_it_got(): void
    {
        $this->askAboutTheReport();

        $this->postJson("/api/conversations/{$this->chat->id}/agent-runs", [
            'body' => 'Thanks',
            'answers' => ['week' => 'Week 40', 'projects' => ['harbor', 'shop']],
        ])->assertStatus(202);

        $this->assertStringEndsWith(
            "User: Thanks\n\nAnswers to your questions:\n- Which week? \"Week 40\" (in their own words)\n- Which projects? Shop, Harbor",
            $this->harness->inputs[1],
        );

        $this->postJson("/api/conversations/{$this->chat->id}/agent-runs", ['answers' => ['week' => 'this-week']])->assertStatus(202);

        $this->assertStringEndsWith("Assistant: Done.\n\nAnswers to your questions:\n- Which week? This week", $this->harness->inputs[2]);
    }
}
