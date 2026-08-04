<?php

namespace App\Http\Requests\ConnectedRealms;

use Illuminate\Foundation\Http\FormRequest;

class StoreRewardLoadoutRequest extends FormRequest
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
            'title_claim_key' => ['nullable', 'string', 'max:160'],
            'badge_claim_key' => ['prohibited'],
            'frame_claim_key' => ['prohibited'],
        ];
    }

    /**
     * @return array{title_claim_key: string|null}
     */
    public function loadout(): array
    {
        $validated = $this->validated();

        return [
            'title_claim_key' => $this->nullableKey($validated['title_claim_key'] ?? null),
        ];
    }

    private function nullableKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
