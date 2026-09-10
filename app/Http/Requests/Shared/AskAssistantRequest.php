<?php

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already requires a signed-in user; which roles the
        // assistant supports is ToolRegistry's call, not this request's.
        return true;
    }

    /**
     * The history is replayed by the browser rather than stored server-side, so
     * it is validated as untrusted input and capped here as well as trimmed
     * again in AssistantService::sanitiseHistory().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Type a question first.',
            'message.max' => 'That question is too long - try a shorter one.',
        ];
    }
}
