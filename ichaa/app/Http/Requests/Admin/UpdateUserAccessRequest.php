<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAccessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'access_roles' => ['array'],
            'access_roles.*' => ['string', Rule::in(array_keys(User::ACCESS_ROLE_LABELS))],
        ];
    }

    /**
     * @return list<string>
     */
    public function accessRoles(): array
    {
        return collect($this->validated('access_roles', []))
            ->unique()
            ->values()
            ->all();
    }
}
