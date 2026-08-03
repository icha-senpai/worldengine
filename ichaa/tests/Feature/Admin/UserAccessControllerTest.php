<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAccessControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_all_users_and_assignable_access_roles(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $member = User::factory()->create([
            'name' => 'Bitcraft Member',
            'email' => 'bitcraft-member@example.com',
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_BITCRAFT, 'web');
        $member->assignRole(User::ROLE_BITCRAFT);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->has('users')
                ->where('roles.0.key', User::ROLE_BITCRAFT)
                ->where('roles.1.key', User::ROLE_CONNECTED_REALMS)
                ->where('roles.2.key', User::ROLE_WORLD_ENGINE)
            );
    }

    public function test_admin_can_create_user_with_access_roles(): void
    {
        $admin = $this->createVerifiedAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'New Member',
                'email' => 'new-member@example.com',
                'password' => 'correct-horse-battery-staple',
                'password_confirmation' => 'correct-horse-battery-staple',
                'verified' => true,
                'access_roles' => [
                    User::ROLE_BITCRAFT,
                ],
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User created.');

        $user = User::query()->where('email', 'new-member@example.com')->firstOrFail();

        $this->assertSame('New Member', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->password));
        $this->assertTrue($user->hasRole(User::ROLE_BITCRAFT));
        $this->assertFalse($user->hasRole(User::ROLE_WORLD_ENGINE));
        $this->assertFalse($user->hasRole(User::ROLE_ADMIN));
    }

    public function test_admin_cannot_create_another_reserved_footmouthkick_user(): void
    {
        $admin = $this->createVerifiedAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => User::FOOTMOUTHKICK_NAME,
                'email' => 'second-footmouthkick@example.com',
                'password' => 'correct-horse-battery-staple',
                'password_confirmation' => 'correct-horse-battery-staple',
                'verified' => true,
                'access_roles' => [],
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', [
            'email' => 'second-footmouthkick@example.com',
        ]);
    }

    public function test_admin_can_edit_user_identity_password_and_access_roles(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $member = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old-member@example.com',
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_BITCRAFT, 'web');
        $member->assignRole(User::ROLE_BITCRAFT);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), [
                'name' => 'Edited Member',
                'email' => 'edited-member@example.com',
                'password' => 'new-correct-horse-battery-staple',
                'password_confirmation' => 'new-correct-horse-battery-staple',
                'verified' => false,
                'access_roles' => [
                    User::ROLE_WORLD_ENGINE,
                ],
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User updated.');

        $member->refresh();

        $this->assertSame('Edited Member', $member->name);
        $this->assertSame('edited-member@example.com', $member->email);
        $this->assertNull($member->email_verified_at);
        $this->assertTrue(Hash::check('new-correct-horse-battery-staple', $member->password));
        $this->assertFalse($member->hasRole(User::ROLE_BITCRAFT));
        $this->assertTrue($member->hasRole(User::ROLE_WORLD_ENGINE));
        $this->assertFalse($member->hasRole(User::ROLE_ADMIN));
    }

    public function test_admin_can_verify_unverified_user(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $member = User::factory()->unverified()->create([
            'name' => 'Unverified Member',
            'email' => 'unverified-member@example.com',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), [
                'name' => 'Unverified Member',
                'email' => 'unverified-member@example.com',
                'password' => '',
                'password_confirmation' => '',
                'verified' => true,
                'access_roles' => [],
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User updated.');

        $this->assertNotNull($member->refresh()->email_verified_at);
    }

    public function test_admin_cannot_assign_reserved_footmouthkick_identity_to_another_user(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $member = User::factory()->create([
            'name' => 'Regular Member',
            'email' => 'regular-member@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), [
                'name' => User::FOOTMOUTHKICK_NAME,
                'email' => 'regular-member@example.com',
                'password' => '',
                'password_confirmation' => '',
                'verified' => true,
                'access_roles' => [],
            ])
            ->assertSessionHasErrors('email');

        $this->assertFalse($member->refresh()->canAccessAdmin());
    }

    public function test_admin_can_assign_bitcraft_and_world_engine_access_without_assigning_admin(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $member = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.access.update', $member), [
                'access_roles' => [
                    User::ROLE_BITCRAFT,
                    User::ROLE_WORLD_ENGINE,
                ],
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User access updated.');

        $member->refresh();

        $this->assertTrue($member->hasRole(User::ROLE_BITCRAFT));
        $this->assertTrue($member->hasRole(User::ROLE_WORLD_ENGINE));
        $this->assertFalse($member->hasRole(User::ROLE_ADMIN));
        $this->assertTrue($member->canAccessBitcraft());
        $this->assertTrue($member->canAccessWorldEngine());
        $this->assertFalse($member->canAccessAdmin());
    }

    public function test_admin_role_is_locked_to_footmouthkick_user_when_access_is_updated(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $otherUser->assignRole(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->put(route('admin.users.access.update', $otherUser), [
                'access_roles' => [],
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->actingAs($admin)
            ->put(route('admin.users.access.update', $admin), [
                'access_roles' => [],
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertFalse($otherUser->refresh()->hasRole(User::ROLE_ADMIN));
        $this->assertTrue($admin->refresh()->hasRole(User::ROLE_ADMIN));
        $this->assertTrue($admin->canAccessAdmin());
    }

    public function test_admin_can_delete_non_reserved_users(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $member = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $member))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User deleted.');

        $this->assertModelMissing($member);
    }

    public function test_admin_cannot_delete_footmouthkick_user(): void
    {
        $admin = $this->createVerifiedAdminUser();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('user');

        $this->assertModelExists($admin);
        $this->assertTrue($admin->refresh()->canAccessAdmin());
    }

    public function test_admin_cannot_edit_reserved_user_identity_away_from_footmouthkick(): void
    {
        $admin = $this->createVerifiedAdminUser();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => 'Someone Else',
                'email' => 'someone-else@example.com',
                'password' => '',
                'password_confirmation' => '',
                'verified' => true,
                'access_roles' => [],
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue($admin->refresh()->canAccessAdmin());
    }

    public function test_admin_cannot_unverify_footmouthkick_user(): void
    {
        $admin = $this->createVerifiedAdminUser();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => User::FOOTMOUTHKICK_NAME,
                'email' => $admin->email,
                'password' => '',
                'password_confirmation' => '',
                'verified' => false,
                'access_roles' => [],
            ])
            ->assertSessionHasErrors('verified');

        $this->assertTrue($admin->refresh()->hasVerifiedEmail());
        $this->assertTrue($admin->canAccessAdmin());
    }

    public function test_non_admin_users_cannot_open_user_access_admin(): void
    {
        $member = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_WORLD_ENGINE, 'web');
        $member->assignRole(User::ROLE_WORLD_ENGINE);

        $this->actingAs($member)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'You do not have access to that Datacrypt section.');
    }
}
