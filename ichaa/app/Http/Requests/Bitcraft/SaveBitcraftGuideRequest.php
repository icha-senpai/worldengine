<?php

namespace App\Http\Requests\Bitcraft;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBitcraftGuideRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:100'],
            'content' => ['required', 'array:type,content'],
            'content.type' => ['required', Rule::in(['doc'])],
            'content.content' => ['required', 'array', 'min:1'],
            'content.content.*.type' => ['required', 'string'],
            'is_published' => ['required', 'boolean'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }

            if (! $this->hasContent($this->input('content'))) {
                $validator->errors()->add('content', 'Write some guide content before saving.');
            }
        }];
    }

    private function hasContent(array $node): bool
    {
        if (($node['type'] ?? null) === 'text' && trim((string) ($node['text'] ?? '')) !== '') {
            return true;
        }

        if (($node['type'] ?? null) === 'image' && ! empty($node['attrs']['src'])) {
            return true;
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child) && $this->hasContent($child)) {
                return true;
            }
        }

        return false;
    }
}
