<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DatacryptAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_renders_the_welcome_page_for_guests(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('canLogin', true)
                ->where('canRegister', true)
                ->where('auth.user', null)
            );
    }

    public function test_guests_are_redirected_to_login_when_visiting_datacrypt(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_datacrypt_root_renders_the_hub_for_admin_users(): void
    {
        $user = $this->createVerifiedAdminUser();

        $this->actingAs($user)
            ->get(route('datacrypt.hub'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Datacrypt/Hub')
                ->where('auth.user.can_access_bitcraft', true)
                ->where('auth.user.can_access_world_engine', true)
            );
    }

    public function test_users_without_datacrypt_access_are_redirected_back_to_public_home_when_visiting_datacrypt(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'You do not have access to that Datacrypt section.');
    }

    public function test_logged_in_non_admin_users_can_view_the_public_home_with_admin_flag_false(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('auth.user.name', $user->name)
                ->where('auth.user.is_admin', false)
            );
    }

    public function test_logged_in_admin_users_can_view_datacrypt_and_get_admin_flag_on_public_home(): void
    {
        $user = $this->createVerifiedAdminUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('auth.user.name', $user->name)
                ->where('auth.user.is_admin', true)
                ->where('auth.user.can_access_admin', true)
                ->where('auth.user.can_access_bitcraft', true)
                ->where('auth.user.can_access_world_engine', true)
            );
    }
}
