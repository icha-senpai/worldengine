<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class CancelMarketListingRequest extends FormRequest
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
        return [];
    }
}
