<?php

namespace Tests\Feature\Admin;

use App\Domain\ConnectedRealms\Models\ConnectedRealmsContentEntry;
use App\Domain\ConnectedRealms\Services\ConnectedRealmsContentService;
use App\Domain\ConnectedRealms\Services\EvergatherTierCatalog;
use App\Domain\ConnectedRealms\Services\SkillCatalogService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EvergatherContentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_evergather_content_panel(): void
    {
        $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('admin.evergather-content.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/EvergatherContent/Index')
                ->where('active_surface', 'tiers')
                ->has('surfaces')
                ->has('entries', 10)
                ->where('entries.0.entry_key', 'starter')
                ->where('entries.0.source', 'code')
            );
    }

    public function test_admin_can_store_database_override_that_updates_runtime_tier_catalog(): void
    {
        $admin = $this->createVerifiedAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.evergather-content.store'), [
                'surface' => 'tiers',
                'entry_key' => 'local',
                'label' => 'Roadsign',
                'category' => '1-30',
                'required_level' => 6,
                'rarity' => 'uncommon',
                'enabled' => true,
                'sort_order' => 10,
                'payload_json' => json_encode([
                    'level' => 6,
                    'band' => '1-30',
                    'key_slug' => 'local',
                    'mark' => 'Roadsign',
                    'station' => 'Roadsign Station',
                    'rarity' => 'uncommon',
                    'experience' => [34, 50],
                    'gold' => [5, 12],
                    'cooldown' => 90,
                ]),
            ])
            ->assertRedirect(route('admin.evergather-content.index', ['surface' => 'tiers']))
            ->assertSessionHas('success', 'Evergather content saved.');

        $this->assertDatabaseHas('connected_realms_content_entries', [
            'surface' => 'tiers',
            'entry_key' => 'local',
            'label' => 'Roadsign',
            'updated_by' => $admin->id,
        ]);

        $tier = collect(EvergatherTierCatalog::tiers())->firstWhere('key_slug', 'local');

        $this->assertSame('Roadsign', $tier['mark']);
        $this->assertSame(6, $tier['level']);
        $this->assertSame('Roadsign Station', $tier['station']);
    }

    public function test_admin_can_disable_database_content_without_deleting_the_record(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $entry = ConnectedRealmsContentEntry::query()->create([
            'surface' => 'tiers',
            'entry_key' => 'local',
            'label' => 'Wayside',
            'category' => '1-30',
            'required_level' => 5,
            'rarity' => 'common',
            'enabled' => true,
            'sort_order' => 0,
            'payload' => [
                'level' => 5,
                'band' => '1-30',
                'key_slug' => 'local',
                'mark' => 'Wayside',
                'station' => 'Wayside Station',
                'rarity' => 'common',
                'experience' => [30, 46],
                'gold' => [4, 10],
                'cooldown' => 85,
            ],
        ]);

        $this->actingAs($admin)
            ->put(route('admin.evergather-content.update', $entry), [
                'surface' => 'tiers',
                'entry_key' => 'local',
                'label' => 'Wayside',
                'category' => '1-30',
                'required_level' => 5,
                'rarity' => 'common',
                'enabled' => false,
                'sort_order' => 0,
                'payload_json' => json_encode($entry->payload),
            ])
            ->assertRedirect(route('admin.evergather-content.index', ['surface' => 'tiers']))
            ->assertSessionHas('success', 'Evergather content updated.');

        $this->assertFalse($entry->refresh()->enabled);
        $this->assertNull(collect(EvergatherTierCatalog::tiers())->firstWhere('key_slug', 'local'));
    }

    public function test_admin_skill_payloads_are_normalized_to_the_ten_tier_ladder(): void
    {
        ConnectedRealmsContentService::forgetSurface('tiers');
        ConnectedRealmsContentService::forgetSurface('skill_definitions');

        ConnectedRealmsContentEntry::query()->create([
            'surface' => 'skill_definitions',
            'entry_key' => 'fishing',
            'label' => 'Fishing',
            'category' => 'Gathering',
            'required_level' => 1,
            'enabled' => true,
            'sort_order' => 0,
            'payload' => [
                'label' => 'Fishing',
                'type' => 'skill',
                'category' => 'Gathering',
                'role' => 'Food, aquatic materials, coastal events',
                'description' => 'Catch fish, scales, shells, and rare tideborn resources.',
                'unlocks' => [
                    1 => 'River casts',
                    10 => 'Coastal shoals',
                    25 => 'Treasure nets',
                    50 => 'Deepwater routes',
                    75 => 'Leviathan tide pools',
                    100 => 'Leviathan angler',
                ],
            ],
        ]);

        $tierLevels = collect(EvergatherTierCatalog::tiers())->pluck('level')->all();

        $this->actingAs($this->createVerifiedAdminUser())
            ->get(route('admin.evergather-content.index', ['surface' => 'skill_definitions']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/EvergatherContent/Index')
                ->where('active_surface', 'skill_definitions')
                ->where('entries', function ($entries) use ($tierLevels): bool {
                    $fishing = collect($entries)->firstWhere('entry_key', 'fishing');

                    return $fishing !== null
                        && array_keys($fishing['payload']['unlocks']) === $tierLevels
                        && count($fishing['payload']['unlocks']) === count($tierLevels)
                        && ! array_key_exists(25, $fishing['payload']['unlocks'])
                        && ! array_key_exists(75, $fishing['payload']['unlocks']);
                })
            );

        $this->assertCount(10, app(SkillCatalogService::class)->definition('fishing')['unlocks']);
    }

    public function test_non_admin_cannot_access_evergather_content_panel(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Role::findOrCreate(User::ROLE_CONNECTED_REALMS, 'web');
        $user->assignRole(User::ROLE_CONNECTED_REALMS);

        $this->actingAs($user)
            ->get(route('admin.evergather-content.index'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', 'You do not have access to that Datacrypt section.');
    }
}
