<?php

namespace App\Http\Requests\Bitcraft;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListBitcraftOpenCraftsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessBitcraft() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'integer', 'min:2', 'max:1000'],
            'region' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'levelUps' => ['nullable', 'boolean'],
            'meetsLevel' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:xp,levels,progress,name'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
