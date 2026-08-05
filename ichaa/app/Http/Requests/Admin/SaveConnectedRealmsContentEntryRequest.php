<?php

namespace App\Http\Requests\Admin;

use App\Domain\ConnectedRealms\Services\ConnectedRealmsContentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConnectedRealmsContentEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entry = $this->route('entry');

        return [
            'surface' => ['required', 'string', Rule::in(array_keys(ConnectedRealmsContentService::surfaces()))],
            'entry_key' => [
                'required',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::unique('connected_realms_content_entries', 'entry_key')
                    ->where(fn ($query) => $query->where('surface', $this->input('surface')))
                    ->ignore(is_object($entry) ? $entry->id : null),
            ],
            'label' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
            'required_level' => ['nullable', 'integer', 'min:1', 'max:100'],
            'rarity' => ['nullable', 'string', Rule::in(['common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic'])],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'payload_json' => ['required', 'json'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $decoded = json_decode((string) $this->validated('payload_json'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
