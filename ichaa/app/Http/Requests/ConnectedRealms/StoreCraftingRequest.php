<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\CraftingService;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCraftingRequest extends FormRequest
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
            'recipe' => ['required', 'string', Rule::in(CraftingService::recipeKeys())],
        ];
    }

    public function recipe(): string
    {
        return (string) $this->validated('recipe');
    }
}
