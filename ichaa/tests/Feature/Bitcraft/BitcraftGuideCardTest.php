<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftGuide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BitcraftGuideCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $path = storage_path('framework/testing/guide-card-snapshot.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'source' => 'bitcraft-spacetimedb', 'generatedAt' => '2026-10-02T12:00:00Z',
            'database' => 'guide-test', 'tables' => [
                'item_desc' => ['rows' => [
                    ['id' => 123, 'name' => 'Crushed Ore', 'tag' => 'Ore', 'tier' => 2],
                    ['id' => 456, 'name' => 'Raw Ore', 'tag' => 'Ore', 'tier' => 2],
                ]],
                'crafting_recipe_desc' => ['rows' => [[
                    'id' => 7, 'name' => 'Crush ore', 'time_requirement' => 4,
                    'crafted_item_stacks' => [[123, 2, 0]],
                    'consumed_item_stacks' => [[456, 3, 0]],
                ]]],
                'extraction_recipe_desc' => ['rows' => [[
                    'id' => 8, 'verb_phrase' => 'Mine', 'resource_id' => 9,
                    'time_requirement' => 10, 'tool_requirements' => [[1, 1, 5]],
                    'level_requirements' => [[1, 1]], 'experience_per_progress' => [[1, 2]],
                    'extracted_item_stacks' => [[[0, [456, 1, 0]], 0.5]],
                ]]],
                'resource_desc' => ['rows' => [['id' => 9, 'name' => 'Copper Vein', 'max_health' => 15]]],
                'skill_desc' => ['rows' => [['id' => 1, 'name' => 'Mining']]],
                'tool_type_desc' => ['rows' => [['id' => 1, 'name' => 'Pickaxe']]],
            ],
        ], JSON_THROW_ON_ERROR));
        config([
            'services.bitcraft_spacetime.enabled' => true,
            'services.bitcraft_spacetime.enabled_in_tests' => true,
            'services.bitcraft_spacetime.static_snapshot_path' => $path,
        ]);
        $this->beforeApplicationDestroyed(fn () => File::delete($path));
    }

    public function test_card_search_is_admin_only_and_filters_out_gathering_only_outputs(): void
    {
        $url = route('bitcraft.guides.card-options', ['activity' => 'crafting', 'q' => 'ore']);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->reader())->get($url)->assertRedirect(route('home'));
        $this->actingAs($this->createVerifiedAdminUser())->getJson($url)
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 123);
        $this->getJson(route('bitcraft.guides.card-options', ['activity' => 'gathering', 'q' => 'mInInG']))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 8);
        $this->getJson(route('bitcraft.guides.card-options', ['activity' => 'crafting', 'q' => 'missing']))
            ->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(route('bitcraft.guides.card-options', ['activity' => 'invalid']))->assertUnprocessable();
    }

    public function test_readers_can_load_recipe_data_and_gathering_probabilities_from_the_snapshot(): void
    {
        $url = route('bitcraft.guides.card-data', ['activity' => 'crafting', 'itemId' => 123, 'recipeId' => 7]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($url)->assertRedirect(route('home'));
        $this->actingAs($this->reader())->getJson($url)->assertOk()
            ->assertJsonPath('recipes.0.consumedItems.0.quantity', 3)
            ->assertJsonPath('recipes.0.craftedItems.0.quantity', 2)
            ->assertJsonPath('snapshot.generatedAt', '2026-10-02T12:00:00Z');
        $this->getJson(route('bitcraft.guides.card-data', ['activity' => 'gathering', 'recipeId' => 8]))
            ->assertOk()->assertJsonPath('entry.skill.name', 'Mining')
            ->assertJsonPath('entry.outputs.0.probability', 0.5)
            ->assertJsonPath('entry.resource.maxHealth', 15);
    }

    public function test_missing_recipes_bad_parameters_and_unavailable_snapshots_have_explicit_failures(): void
    {
        $this->actingAs($this->reader());
        $this->getJson(route('bitcraft.guides.card-data', ['activity' => 'crafting']))->assertUnprocessable();
        $this->getJson(route('bitcraft.guides.card-data', ['activity' => 'gathering']))->assertUnprocessable();
        $this->getJson(route('bitcraft.guides.card-data', ['activity' => 'gathering', 'recipeId' => 999]))->assertNotFound();
        $this->getJson(route('bitcraft.guides.card-data', ['activity' => 'crafting', 'itemId' => 123, 'recipeId' => 999]))->assertNotFound();
        config(['services.bitcraft_spacetime.enabled' => false]);
        $this->getJson(route('bitcraft.guides.card-data', ['activity' => 'gathering', 'recipeId' => 8]))->assertStatus(503);
    }

    public function test_activity_cards_preserve_author_defaults_and_readers_cannot_update_them(): void
    {
        $content = ['type' => 'doc', 'content' => [$this->card('crafting'), $this->card('gathering')]];
        $this->actingAs($this->createVerifiedAdminUser())->post(route('bitcraft.guides.store'), [
            'title' => 'Activity guide', 'content' => $content, 'is_published' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $guide = BitcraftGuide::query()->sole();
        $this->assertEquals($content, $guide->content);
        $this->actingAs($this->reader())->get(route('bitcraft.guides.show', $guide))
            ->assertInertia(fn (Assert $page) => $page->where('guide.content.content.1.attrs.settings.power', 10));
        $this->put(route('bitcraft.guides.update', $guide), [
            'title' => 'Reader edit', 'content' => $content, 'is_published' => true,
        ])->assertRedirect(route('home'));
        $this->assertEquals($content, $guide->refresh()->content);
    }

    public function test_invalid_activity_defaults_are_rejected_before_saving(): void
    {
        $card = $this->card('gathering');
        $card['attrs']['settings']['critChance'] = 101;
        $this->actingAs($this->createVerifiedAdminUser())->post(route('bitcraft.guides.store'), [
            'title' => 'Bad card', 'is_published' => false, 'content' => ['type' => 'doc', 'content' => [$card]],
        ])->assertSessionHasErrors('content.content.0.attrs.settings.critChance');
        $card = $this->card('crafting');
        $card['attrs']['settings']['unknown'] = true;
        $this->post(route('bitcraft.guides.store'), [
            'title' => 'Bad card', 'is_published' => false, 'content' => ['type' => 'doc', 'content' => [$card]],
        ])->assertSessionHasErrors('content.content.0.attrs.settings');
        $this->assertDatabaseCount('bitcraft_guides', 0);
    }

    public function test_layout_is_saved_for_item_crafting_and_gathering_cards(): void
    {
        $cards = [
            ['type' => 'bitcraftItem', 'attrs' => ['id' => 123, 'kind' => 'item', 'name' => 'Ore']],
            $this->card('crafting'), $this->card('gathering'),
        ];
        foreach ($cards as $index => &$card) {
            $card['attrs']['align'] = ['left', 'center', 'right'][$index];
            $card['attrs']['width'] = [25, 50, 100][$index];
            $card['attrs']['wrap'] = $index !== 1;
        }
        unset($card);
        $content = ['type' => 'doc', 'content' => $cards];
        $this->actingAs($this->createVerifiedAdminUser())->post(route('bitcraft.guides.store'), [
            'title' => 'Card layouts', 'content' => $content, 'is_published' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $guide = BitcraftGuide::query()->sole();
        $this->assertEquals($content, $guide->content);
        $this->actingAs($this->reader())->get(route('bitcraft.guides.show', $guide))
            ->assertInertia(fn (Assert $page) => $page
                ->where('guide.content.content.0.attrs.width', 25)
                ->where('guide.content.content.0.attrs.wrap', true)
                ->where('guide.content.content.1.attrs.align', 'center')
                ->where('guide.content.content.2.attrs.align', 'right'));
    }

    public function test_invalid_card_layouts_are_rejected(): void
    {
        $this->actingAs($this->createVerifiedAdminUser());
        foreach ([
            ['type' => 'bitcraftItem', 'attrs' => ['id' => 123, 'kind' => 'item', 'name' => 'Ore']],
            $this->card('crafting'), $this->card('gathering'),
        ] as $card) {
            foreach ([24, 101, '50%; position:fixed'] as $width) {
                $card['attrs']['width'] = $width;
                $this->post(route('bitcraft.guides.store'), [
                    'title' => 'Bad size', 'is_published' => false, 'content' => ['type' => 'doc', 'content' => [$card]],
                ])->assertSessionHasErrors('content.content.0.attrs.width');
            }
            $card['attrs']['width'] = null;
            $card['attrs']['align'] = 'justify';
            $this->post(route('bitcraft.guides.store'), [
                'title' => 'Bad alignment', 'is_published' => false, 'content' => ['type' => 'doc', 'content' => [$card]],
            ])->assertSessionHasErrors('content.content.0.attrs.align');
            $card['attrs']['align'] = 'left';
            $card['attrs']['wrap'] = 'yes';
            $this->post(route('bitcraft.guides.store'), [
                'title' => 'Bad wrapping', 'is_published' => false, 'content' => ['type' => 'doc', 'content' => [$card]],
            ])->assertSessionHasErrors('content.content.0.attrs.wrap');
        }
        $this->assertDatabaseCount('bitcraft_guides', 0);
    }

    private function reader(): User
    {
        $user = User::factory()->create();
        Role::findOrCreate(User::ROLE_BITCRAFT, 'web');
        $user->assignRole(User::ROLE_BITCRAFT);

        return $user;
    }

    private function card(string $activity): array
    {
        return ['type' => 'bitcraftActivity', 'attrs' => [
            'activity' => $activity, 'recipeId' => $activity === 'crafting' ? 7 : 8,
            'itemId' => $activity === 'crafting' ? 123 : null, 'kind' => 'item', 'name' => 'Ore recipe',
            'settings' => ['quantity' => 5, 'power' => 10, 'minutes' => 1, 'market' => false, 'region' => ''],
        ]];
    }
}
