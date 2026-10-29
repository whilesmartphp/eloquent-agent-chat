<?php

namespace Whilesmart\AgentChat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerRequest;

class StoreAgentTurnRequest extends FormRequest
{
    use AuthorizesOwnerRequest;

    public function authorize(): bool
    {
        return $this->authorizeOwnerOfBoundModel('conversation');
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
            'harness' => ['nullable', 'string', Rule::in($this->allowedHarnesses())],
        ];
    }

    public function harness(): string
    {
        return (string) ($this->validated('harness') ?? config('agent-chat.default_harness', 'assistant'));
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
