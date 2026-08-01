<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UpdateUserRequest extends FormRequest
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
        /** @var User|null $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user),
            ],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'verified' => ['required', 'boolean'],
            'access_roles' => ['array'],
            'access_roles.*' => ['string', Rule::in(array_keys(User::ACCESS_ROLE_LABELS))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var User|null $user */
            $user = $this->route('user');

            $name = (string) $this->input('name', '');
            $email = (string) $this->input('email', '');

            if (! $user?->isFootmouthkickUser()) {
                if (
                    strcasecmp($name, User::FOOTMOUTHKICK_NAME) === 0
                    || strcasecmp($email, User::FOOTMOUTHKICK_EMAIL) === 0
                ) {
                    $validator->errors()->add(
                        'email',
                        'The reserved footmouthkick admin identity cannot be assigned to another user.'
                    );
                }

                return;
            }

            if (! $this->boolean('verified')) {
                $validator->errors()->add(
                    'verified',
                    'The footmouthkick admin account must stay verified.'
                );
            }

            if (
                strcasecmp($name, User::FOOTMOUTHKICK_NAME) !== 0
                && strcasecmp($email, User::FOOTMOUTHKICK_EMAIL) !== 0
            ) {
                $validator->errors()->add(
                    'email',
                    'The footmouthkick admin account must keep its reserved name or email.'
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
