<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatacryptRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_bitcraft_role_can_open_bitcraft_without_world_engine_access(): void
    {
        $user = $this->verifiedUserWithRole(User::ROLE_BITCRAFT);

        $this->actingAs($user)
            ->get('/datacrypt')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Datacrypt/Hub')
                ->where('auth.user.can_access_bitcraft', true)
                ->where('auth.user.can_access_world_engine', false)
            );

        $this->actingAs($user)
            ->get(route('bitcraft.task-tracker.setup', ['source' => 'default']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Bitcraft/TaskTracker')
            );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'You do not have access to that Datacrypt section.');
    }

    public function test_world_engine_role_can_open_world_engine_without_bitcraft_access(): void
    {
        $user = $this->verifiedUserWithRole(User::ROLE_WORLD_ENGINE);

        $this->actingAs($user)
            ->get('/datacrypt')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Datacrypt/Hub')
                ->where('auth.user.can_access_bitcraft', false)
                ->where('auth.user.can_access_world_engine', true)
            );

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('bitcraft.task-tracker.setup', ['source' => 'default']))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'You do not have access to that Datacrypt section.');
    }

    public function test_admin_role_does_not_grant_access_to_non_footmouthkick_users(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $user->assignRole(User::ROLE_ADMIN);

        $this->assertFalse($user->isAdmin());

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('home'));
    }

    private function verifiedUserWithRole(string $role): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }
}
