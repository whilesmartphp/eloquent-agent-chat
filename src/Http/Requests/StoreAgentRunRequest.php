<?php

namespace Whilesmart\AgentChat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Whilesmart\AgentChat\Attachments\Attachment;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerRequest;

class StoreAgentRunRequest extends FormRequest
{
    use AuthorizesOwnerRequest;

    public function authorize(): bool
    {
        return $this->authorizeOwnerOfBoundModel('conversation');
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:attachments', 'string', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:'.(int) config('agent-chat.attachments.max', 10)],
            ...Attachment::requestRules('attachments.*'),
            'harness' => ['nullable', 'string', Rule::in($this->allowedHarnesses())],
            'language' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/'],
            'options' => ['nullable', 'array'],
            'hold' => ['nullable', 'boolean'],
        ];
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
