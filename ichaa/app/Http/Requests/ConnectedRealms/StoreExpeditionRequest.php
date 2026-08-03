<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\ExpeditionService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpeditionRequest extends FormRequest
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
            'expedition' => ['required', 'string', Rule::in(ExpeditionService::expeditionKeys())],
        ];
    }

    public function expedition(): string
    {
        return (string) $this->validated('expedition');
    }
}
