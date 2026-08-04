<?php

namespace App\Http\Requests\ConnectedRealms;

use Illuminate\Foundation\Http\FormRequest;

class StoreAchievementClaimRequest extends FormRequest
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
            'achievement' => ['required', 'string', 'max:160'],
        ];
    }

    public function achievementKey(): string
    {
        return (string) $this->validated('achievement');
    }
}
