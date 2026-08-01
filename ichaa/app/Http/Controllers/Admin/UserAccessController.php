<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserAccessRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserAccessController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('roles')
            ->orderBy('name')
            ->orderBy('email')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'is_footmouthkick_user' => $user->isFootmouthkickUser(),
                'is_verified' => $user->hasVerifiedEmail(),
                'can_access_bitcraft' => $user->canAccessBitcraft(),
                'can_access_world_engine' => $user->canAccessWorldEngine(),
                'access_roles' => $user->accessRoleNames(),
                'can_delete' => ! $user->isFootmouthkickUser(),
                'can_edit_reserved_identity' => ! $user->isFootmouthkickUser(),
                'updated_at' => optional($user->updated_at)->toIso8601String(),
            ])
            ->values();

        return $this->page('Admin/Users/Index', [
            'users' => $users,
            'roles' => collect(User::ACCESS_ROLE_LABELS)
                ->map(fn (string $label, string $key): array => [
                    'key' => $key,
                    'label' => $label,
                ])
                ->values(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->ensureAccessRolesExist();

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $this->syncEmailVerification($user, $request->boolean('verified'));

        $this->syncAccessRoles($user, $request->accessRoles());

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->ensureAccessRolesExist();

        $user->fill([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
        ]);

        $password = $request->validated('password');

        if (is_string($password) && $password !== '') {
            $user->password = Hash::make($password);
        }

        $user->save();

        $this->syncEmailVerification($user, $request->boolean('verified'));

        $this->syncAccessRoles($user, $request->accessRoles());

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    public function updateAccess(UpdateUserAccessRequest $request, User $user): RedirectResponse
    {
        $this->ensureAccessRolesExist();

        $this->syncAccessRoles($user, $request->accessRoles());

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User access updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->isFootmouthkickUser()) {
            throw ValidationException::withMessages([
                'user' => 'The footmouthkick admin account cannot be deleted.',
            ]);
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User deleted.');
    }

    private function ensureAccessRolesExist(): void
    {
        Role::findOrCreate(User::ROLE_ADMIN, 'web');

        foreach (array_keys(User::ACCESS_ROLE_LABELS) as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function syncEmailVerification(User $user, bool $isVerified): void
    {
        $user->forceFill([
            'email_verified_at' => $isVerified ? ($user->email_verified_at ?? now()) : null,
        ])->save();
    }

    /**
     * @param  list<string>  $requestedRoles
     */
    private function syncAccessRoles(User $user, array $requestedRoles): void
    {
        foreach (array_keys(User::ACCESS_ROLE_LABELS) as $role) {
            if (in_array($role, $requestedRoles, true)) {
                $user->assignRole($role);
            } else {
                $user->removeRole($role);
            }
        }

        if ($user->isFootmouthkickUser()) {
            $user->assignRole(User::ROLE_ADMIN);
        } else {
            $user->removeRole(User::ROLE_ADMIN);
        }
    }
}
