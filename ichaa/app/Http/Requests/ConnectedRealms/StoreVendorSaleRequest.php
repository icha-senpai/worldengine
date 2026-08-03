<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreVendorSaleRequest extends FormRequest
{
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
            'item_key' => ['required', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    public function itemKey(): string
    {
        return (string) $this->validated('item_key');
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
