<?php

namespace Whilesmart\AgentChat\Blocks;

use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\Conversations\Models\Message;

/**
 * Links question blocks to the prompts that answered them. A prompt stores
 * its answers in its own metadata, keyed by question id, beside the id of the
 * reply that asked. Reply blocks are never rewritten: the answer is looked up
 * when the reply is read.
 */
class QuestionAnswers
{
    /**
     * The question blocks among these blocks, including those in a canvas,
     * keyed by question id.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<string, array<string, mixed>>
     */
    public function questions(array $blocks): array
    {
        $questions = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'question' && is_scalar($block['id'] ?? null)) {
                $questions[(string) $block['id']] = $block;
            }

            if (($block['type'] ?? null) === 'canvas' && is_array($block['blocks'] ?? null)) {
                $questions += $this->questions($block['blocks']);
            }
        }

        return $questions;
    }

    /**
     * The latest reply in the conversation that asked questions.
     */
    public function latestAsked(int|string $conversationId): ?Message
    {
        $model = config('agent-chat.model', AgentRun::class);

        $runs = $model::query()
            ->where('conversation_id', $conversationId)
            ->whereNotNull('reply_message_id')
            ->latest('id')
            ->with('replyMessage')
            ->get();

        foreach ($runs as $run) {
            if ($this->questions($run->blocks()) !== []) {
                return $run->replyMessage;
            }
        }

        return null;
    }

    /**
     * Why each answer cannot be accepted, keyed by question id.
     *
     * @param  array<string, array<string, mixed>>  $questions
     * @param  array<mixed>  $answers
     * @return array<string, string>
     */
    public function errors(array $questions, array $answers): array
    {
        $errors = [];

        foreach ($answers as $id => $value) {
            $question = $questions[(string) $id] ?? null;

            if ($question === null) {
                $errors[(string) $id] = 'There is no question with this id in the latest reply.';

                continue;
            }

            if ($this->resolve($question, $value) === null) {
                $errors[(string) $id] = $this->hint($question);
            }
        }

        return $errors;
    }

    /**
     * Read one answer: an option id, a list of option ids, or free text when
     * the question takes a written answer. Null when it does not fit.
     *
     * @param  array<string, mixed>  $question
     * @return array{options: array<int, string>, text: string|null}|null
     */
    public function resolve(array $question, mixed $value): ?array
    {
        $optionIds = array_map(fn ($option) => (string) ($option['id'] ?? ''), (array) ($question['options'] ?? []));

        if (is_string($value) && in_array($value, $optionIds, true)) {
            return ['options' => [$value], 'text' => null];
        }

        if (is_string($value)) {
            $text = trim($value);

            return ($question['write_in'] ?? false) !== false && $text !== '' && mb_strlen($text) <= 2000
                ? ['options' => [], 'text' => $text]
                : null;
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $choice) {
            if (! is_string($choice) || ! in_array($choice, $optionIds, true)) {
                return null;
            }
        }

        $choices = array_values(array_unique($value));

        if (count($choices) > 1 && ! ($question['multiple'] ?? false)) {
            return null;
        }

        return ['options' => $choices, 'text' => null];
    }

    /**
     * The blocks with each question's `answer` and `answered_by_message_id`
     * filled in from the later prompt that answered it, or null.
     *
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    public function annotate(AgentRun $run, array $blocks): array
    {
        if ($run->reply_message_id === null || $this->questions($blocks) === []) {
            return $blocks;
        }

        return $this->fill($blocks, $this->answersTo($run->conversation_id, $run->reply_message_id));
    }

    /**
     * The text the agent reads for a prompt that answered questions: each
     * question's prompt with the chosen labels or the written answer.
     */
    public function describe(Message $prompt): ?string
    {
        $answers = $prompt->metadata['answers'] ?? null;
        $askedId = $prompt->metadata['answers_to_message_id'] ?? null;

        if (! is_array($answers) || $answers === [] || $askedId === null) {
            return null;
        }

        $messageModel = config('conversations.message_model', Message::class);
        $asked = $messageModel::query()->find($askedId);
        $questions = $this->questions((array) ($asked?->metadata['blocks'] ?? []));
        $lines = [];

        foreach ($answers as $id => $value) {
            $question = $questions[(string) $id] ?? null;
            $answer = $question === null ? null : $this->resolve($question, $value);

            if ($answer !== null) {
                $lines[] = '- '.$question['prompt'].' '.$this->summary($question, $answer);
            }
        }

        return $lines === [] ? null : "Answers to your questions:\n".implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array{options: array<int, string>, text: string|null}  $answer
     */
    public function summary(array $question, array $answer): string
    {
        if ($answer['text'] !== null) {
            return '"'.$answer['text'].'" (in their own words)';
        }

        $labels = [];

        foreach ((array) ($question['options'] ?? []) as $option) {
            if (in_array((string) ($option['id'] ?? ''), $answer['options'], true)) {
                $labels[] = (string) ($option['label'] ?? $option['id']);
            }
        }

        return implode(', ', $labels);
    }

    /**
     * The first answer each question got from a prompt that answered this
     * reply: question id => [answer, message id].
     *
     * @return array<string, array{0: array{options: array<int, string>, text: string|null}, 1: int|string}>
     */
    protected function answersTo(int|string $conversationId, int|string $replyId): array
    {
        $messageModel = config('conversations.message_model', Message::class);
        $reply = $messageModel::query()->find($replyId);
        $questions = $this->questions((array) ($reply?->metadata['blocks'] ?? []));

        $prompts = $messageModel::query()
            ->where('conversation_id', $conversationId)
            ->where('id', '>', $replyId)
            ->whereNotNull('metadata')
            ->oldest('id')
            ->get();

        $found = [];

        foreach ($prompts as $prompt) {
            if ((string) ($prompt->metadata['answers_to_message_id'] ?? '') !== (string) $replyId) {
                continue;
            }

            foreach ((array) ($prompt->metadata['answers'] ?? []) as $id => $value) {
                $question = $questions[(string) $id] ?? null;
                $answer = $question === null ? null : $this->resolve($question, $value);

                if ($answer !== null && ! isset($found[(string) $id])) {
                    $found[(string) $id] = [$answer, $prompt->getKey()];
                }
            }
        }

        return $found;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<string, array{0: array<string, mixed>, 1: int|string}>  $found
     * @return array<int, mixed>
     */
    private function fill(array $blocks, array $found): array
    {
        foreach ($blocks as $i => $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'question' && is_scalar($block['id'] ?? null)) {
                [$answer, $messageId] = $found[(string) $block['id']] ?? [null, null];
                $blocks[$i] = [...$block, 'answer' => $answer, 'answered_by_message_id' => $messageId];
            }

            if (($block['type'] ?? null) === 'canvas' && is_array($block['blocks'] ?? null)) {
                $blocks[$i]['blocks'] = $this->fill($block['blocks'], $found);
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function hint(array $question): string
    {
        if ($question['multiple'] ?? false) {
            return 'Answer with option ids from this question'.(($question['write_in'] ?? false) !== false ? ', or with text.' : '.');
        }

        return 'Answer with one option id from this question'.(($question['write_in'] ?? false) !== false ? ', or with text.' : '.');
    }
}
