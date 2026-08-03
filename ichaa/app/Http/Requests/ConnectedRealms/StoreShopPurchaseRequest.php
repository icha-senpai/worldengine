<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\ShopService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShopPurchaseRequest extends FormRequest
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
            'offer' => ['required', 'string', Rule::in(ShopService::offerKeys())],
        ];
    }

    public function offer(): string
    {
        return (string) $this->validated('offer');
    }
}
