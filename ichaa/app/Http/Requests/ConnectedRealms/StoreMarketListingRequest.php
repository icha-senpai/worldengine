<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMarketListingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'listing_type' => $this->input('listing_type', 'item'),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->canAccessArea(User::ROLE_CONNECTED_REALMS) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'listing_type' => ['required', 'string', Rule::in(['item', 'tool'])],
            'item_key' => ['required_if:listing_type,item', 'nullable', 'string', 'max:120'],
            'tool_id' => ['required_if:listing_type,tool', 'nullable', 'integer', 'min:1'],
            'quantity' => ['required_if:listing_type,item', 'integer', 'min:1', 'max:999999'],
            'unit_price' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    public function listingType(): string
    {
        return (string) $this->validated('listing_type', 'item');
    }

    public function itemKey(): string
    {
        return (string) $this->validated('item_key');
    }

    public function toolId(): ?int
    {
        return $this->validated('tool_id') === null ? null : (int) $this->validated('tool_id');
    }

    public function quantity(): int
    {
        return $this->listingType() === 'tool' ? 1 : (int) $this->validated('quantity');
    }

    public function unitPrice(): int
    {
        return (int) $this->validated('unit_price');
    }
}
