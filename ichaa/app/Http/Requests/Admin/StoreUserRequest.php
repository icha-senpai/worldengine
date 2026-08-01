<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class StoreUserRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'verified' => ['required', 'boolean'],
            'access_roles' => ['array'],
            'access_roles.*' => ['string', Rule::in(array_keys(User::ACCESS_ROLE_LABELS))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (
                strcasecmp((string) $this->input('name', ''), User::FOOTMOUTHKICK_NAME) === 0
                || strcasecmp((string) $this->input('email', ''), User::FOOTMOUTHKICK_EMAIL) === 0
            ) {
                $validator->errors()->add(
                    'email',
                    'The reserved footmouthkick admin identity cannot be used for a new user.'
                );
            }
        });
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
