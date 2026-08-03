<?php

namespace App\Http\Requests\ConnectedRealms;

use App\Domain\ConnectedRealms\Services\ConnectedRealmsPlayerService;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCharacterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessArea(User::ROLE_CONNECTED_REALMS) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:40'],
            'title' => ['nullable', 'string', 'max:48'],
            'species' => ['required', 'string', Rule::in(ConnectedRealmsPlayerService::speciesKeys())],
            'pronouns' => ['nullable', 'string', 'max:32'],
            'home_region' => ['required', 'string', Rule::in(ConnectedRealmsPlayerService::homeRegionKeys())],
            'appearance' => ['required', 'array:body_style,palette,hair_style,outfit'],
            'appearance.body_style' => ['required', 'string', Rule::in(ConnectedRealmsPlayerService::appearanceKeys('body_style'))],
            'appearance.palette' => ['required', 'string', Rule::in(ConnectedRealmsPlayerService::appearanceKeys('palette'))],
            'appearance.hair_style' => ['required', 'string', Rule::in(ConnectedRealmsPlayerService::appearanceKeys('hair_style'))],
            'appearance.outfit' => ['required', 'string', Rule::in(ConnectedRealmsPlayerService::appearanceKeys('outfit'))],
        ];
    }
}
