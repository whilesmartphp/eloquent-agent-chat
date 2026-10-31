<?php

namespace Whilesmart\AgentChat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Whilesmart\AgentChat\Attachments\Attachment;
use Whilesmart\AgentChat\Blocks\QuestionAnswers;
use Whilesmart\Conversations\Models\Message;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerRequest;

class StoreAgentRunRequest extends FormRequest
{
    use AuthorizesOwnerRequest;

    protected ?Message $asked = null;

    public function authorize(): bool
    {
        return $this->authorizeOwnerOfBoundModel('conversation');
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without_all:attachments,answers', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('agent-chat.attachments.max', 10)],
            ...Attachment::requestRules('attachments.*'),
            'harness' => ['nullable', 'string', Rule::in($this->allowedHarnesses())],
            'language' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/'],
            'options' => ['nullable', 'array'],
            'hold' => ['nullable', 'boolean'],
            'answers' => ['nullable', 'array'],
        ];
    }

    /**
     * Answers must name questions in the conversation's latest reply that
     * asked any, and fit each question's options.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $answers = $this->input('answers');

            if ($validator->errors()->isNotEmpty() || ! is_array($answers) || $answers === []) {
                return;
            }

            $conversation = $this->route('conversation');
            $questions = app(QuestionAnswers::class);
            $this->asked = $conversation === null ? null : $questions->latestAsked($conversation->getKey());

            if ($this->asked === null) {
                $validator->errors()->add('answers', 'There are no questions to answer in this conversation.');

                return;
            }

            $asked = $questions->questions((array) ($this->asked->metadata['blocks'] ?? []));

            foreach ($questions->errors($asked, $answers) as $id => $error) {
                $validator->errors()->add("answers.{$id}", $error);
            }
        }];
    }

    /**
     * The prompt message's metadata: the answers keyed by question id and
     * the reply that asked them.
     *
     * @return array<string, mixed>|null
     */
    public function promptMetadata(): ?array
    {
        $answers = $this->validated('answers');

        if (! is_array($answers) || $answers === [] || $this->asked === null) {
            return null;
        }

        return ['answers' => $answers, 'answers_to_message_id' => $this->asked->getKey()];
    }

    public function harness(): string
    {
        return (string) ($this->validated('harness') ?? config('agent-chat.default_harness', 'assistant'));
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function attachments(): ?array
    {
        $attachments = array_map(
            fn (array $attachment) => Attachment::normalize($attachment),
            array_values((array) $this->validated('attachments', [])),
        );

        return $attachments === [] ? null : $attachments;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function options(): ?array
    {
        $options = $this->validated('options');

        return is_array($options) && $options !== [] ? $options : null;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedHarnesses(): array
    {
        return array_values(array_unique([
            (string) config('agent-chat.default_harness', 'assistant'),
            ...array_map('strval', (array) config('agent-chat.harnesses', [])),
        ]));
    }
}
