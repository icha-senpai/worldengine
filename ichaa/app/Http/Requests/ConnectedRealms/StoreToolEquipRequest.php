<?php

namespace App\Http\Requests\ConnectedRealms;

use Illuminate\Foundation\Http\FormRequest;

class StoreToolEquipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessConnectedRealms() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tool_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toolId(): int
    {
        return (int) $this->validated('tool_id');
    }
}
