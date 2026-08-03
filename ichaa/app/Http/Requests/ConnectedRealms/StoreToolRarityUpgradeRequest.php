<?php

namespace App\Http\Requests\ConnectedRealms;

use Illuminate\Foundation\Http\FormRequest;

class StoreToolRarityUpgradeRequest extends FormRequest
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
            'slot' => ['required', 'string', 'max:40'],
        ];
    }

    public function slot(): string
    {
        return (string) $this->validated('slot');
    }
}
