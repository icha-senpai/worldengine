<?php

namespace Tests\Feature\Bitcraft;

use App\Domain\Bitcraft\Models\BitcraftGuide;
use App\Domain\Bitcraft\Services\BitcraftSpacetimeStaticData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BitcraftGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_users_without_bitcraft_access_cannot_read_guides(): void
    {
        $this->get(route('bitcraft.guides.index'))->assertRedirect(route('login'));
        $this->post(route('bitcraft.guides.store'), $this->payload())->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('bitcraft.guides.index'))->assertRedirect(route('home'));
    }

    public function test_readers_only_see_published_guides_and_cannot_read_drafts_or_future_posts(): void
    {
        $published = BitcraftGuide::factory()->published()->create();
        $draft = BitcraftGuide::factory()->create();
        $future = BitcraftGuide::factory()->create(['published_at' => now()->addDay()]);

        $this->actingAs($this->reader())->get(route('bitcraft.guides.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bitcraft/Guides/Index')
            ->where('canManage', false)
            ->has('guides.data', 1)
            ->where('guides.data.0.id', $published->id)
            ->missing('guides.data.0.content')
            ->missing('guides.data.0.author.email')
            );

        $this->get(route('bitcraft.guides.show', $published))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bitcraft/Guides/Show')
            ->where('guide.content', $published->content)
            ->where('canManage', false)
            );
        $this->get(route('bitcraft.guides.show', $draft))->assertNotFound();
        $this->get(route('bitcraft.guides.show', $future))->assertNotFound();
        $this->get(route('bitcraft.guides.show', 999999))->assertNotFound();
    }

    public function test_admin_can_list_preview_create_and_edit_drafts(): void
    {
        $draft = BitcraftGuide::factory()->create();
        $this->actingAs($this->createVerifiedAdminUser());

        $this->get(route('bitcraft.guides.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('guides.data', 1));
        $this->get(route('bitcraft.guides.show', $draft))->assertOk();
        $this->get(route('bitcraft.guides.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Bitcraft/Guides/Form')->where('guide', null));
        $this->get(route('bitcraft.guides.edit', $draft))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Bitcraft/Guides/Form')->where('guide.id', $draft->id));
    }

    public function test_admin_can_save_a_draft_and_publish_a_new_guide_with_server_owned_author_and_date(): void
    {
        $admin = $this->createVerifiedAdminUser();
        $this->actingAs($admin)->post(route('bitcraft.guides.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('success', 'Guide saved.');

        $draft = BitcraftGuide::query()->sole();
        $this->assertNull($draft->published_at);
        $this->assertSame($admin->id, $draft->user_id);
        $this->assertEquals($this->payload()['content'], $draft->content);

        $this->freezeTime();
        $this->post(route('bitcraft.guides.store'), [
            ...$this->payload(),
            'title' => 'Published guide',
            'is_published' => true,
            'user_id' => User::factory()->create()->id,
            'published_at' => now()->subYear()->toIso8601String(),
        ])->assertRedirect();

        $published = BitcraftGuide::query()->where('title', 'Published guide')->sole();
        $this->assertSame($admin->id, $published->user_id);
        $this->assertTrue($published->published_at->equalTo(now()->startOfSecond()));
    }

    public function test_item_search_is_admin_only_and_returns_bounded_catalog_results(): void
    {
        $this->get(route('bitcraft.guides.items'))->assertRedirect(route('login'));
        $this->actingAs($this->reader())->get(route('bitcraft.guides.items'))->assertRedirect(route('home'));

        $this->mock(BitcraftSpacetimeStaticData::class, function ($mock): void {
            $mock->shouldReceive('catalogSearch')->once()->with('copper', 40)->andReturn([$this->itemCard()['attrs']]);
            $mock->shouldReceive('isAvailable')->once()->andReturn(true);
        });
        $this->actingAs($this->createVerifiedAdminUser())
            ->getJson(route('bitcraft.guides.items', ['q' => ' copper ']))
            ->assertOk()->assertJsonPath('items.0.name', 'Crushed Copper Ore')->assertJsonPath('available', true);
        $this->getJson(route('bitcraft.guides.items', ['q' => str_repeat('a', 256)]))->assertUnprocessable();
    }

    public function test_item_only_guides_can_be_saved_read_and_edited_without_losing_cards(): void
    {
        $content = ['type' => 'doc', 'content' => [$this->itemCard()]];
        $this->actingAs($this->createVerifiedAdminUser())->post(route('bitcraft.guides.store'), [
            ...$this->payload(), 'content' => $content, 'is_published' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $guide = BitcraftGuide::query()->sole();
        $this->assertEquals($content, $guide->content);
        $this->get(route('bitcraft.guides.edit', $guide))->assertInertia(fn (Assert $page) => $page
            ->where('guide.content.content.0.attrs.name', 'Crushed Copper Ore')
        );
        $this->put(route('bitcraft.guides.update', $guide), [
            ...$this->payload(), 'content' => $content, 'is_published' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->reader())->get(route('bitcraft.guides.show', $guide))->assertInertia(fn (Assert $page) => $page
            ->where('guide.content.content.0.type', 'bitcraftItem')
            ->where('guide.content.content.0.attrs.id', 123)
        );
    }

    public function test_malformed_item_cards_are_rejected_including_nested_cards(): void
    {
        $card = $this->itemCard();
        $card['attrs']['id'] = -1;
        $this->actingAs($this->createVerifiedAdminUser())->post(route('bitcraft.guides.store'), [
            ...$this->payload(),
            'content' => ['type' => 'doc', 'content' => [
                ['type' => 'blockquote', 'content' => [$card]],
            ]],
        ])->assertSessionHasErrors('content.content.0.content.0.attrs.id');
        $this->post(route('bitcraft.guides.store'), [
            ...$this->payload(),
            'content' => ['type' => 'doc', 'content' => [['type' => 'bitcraftItem', 'attrs' => 'invalid']]],
        ])->assertSessionHasErrors('content.content.0.attrs');
        $this->assertDatabaseCount('bitcraft_guides', 0);
    }

    public function test_admin_can_publish_edit_and_unpublish_without_replacing_the_author_or_publication_date(): void
    {
        $guide = BitcraftGuide::factory()->create();
        $authorId = $guide->user_id;
        $this->actingAs($this->createVerifiedAdminUser());
        $this->freezeTime();

        $this->put(route('bitcraft.guides.update', $guide), [
            ...$this->payload(), 'is_published' => true,
        ])->assertRedirect(route('bitcraft.guides.show', $guide));
        $publishedAt = $guide->refresh()->published_at->copy();

        $this->travel(2)->days();
        $this->put(route('bitcraft.guides.update', $guide), [
            ...$this->payload(), 'title' => 'Updated crafting guide', 'is_published' => true,
        ])->assertRedirect()->assertSessionHas('success', 'Guide updated.');
        $guide->refresh();
        $this->assertSame('Updated crafting guide', $guide->title);
        $this->assertSame($authorId, $guide->user_id);
        $this->assertTrue($guide->published_at->equalTo($publishedAt));

        $this->put(route('bitcraft.guides.update', $guide), $this->payload())->assertRedirect();
        $this->assertNull($guide->refresh()->published_at);
        $this->actingAs($this->reader())->get(route('bitcraft.guides.show', $guide))->assertNotFound();
    }

    public function test_readers_and_an_admin_role_without_existing_admin_identity_cannot_manage_guides(): void
    {
        $guide = BitcraftGuide::factory()->published()->create();
        $reader = $this->reader();
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $impostor = $this->reader();
        $impostor->assignRole(User::ROLE_ADMIN);

        foreach ([$reader, $impostor] as $user) {
            $this->actingAs($user);
            $this->get(route('bitcraft.guides.create'))->assertRedirect(route('home'));
            $this->get(route('bitcraft.guides.edit', $guide))->assertRedirect(route('home'));
            $this->post(route('bitcraft.guides.store'), $this->payload())->assertRedirect(route('home'));
            $this->put(route('bitcraft.guides.update', $guide), $this->payload())->assertRedirect(route('home'));
        }

        $this->assertDatabaseCount('bitcraft_guides', 1);
        $this->assertSame($guide->title, $guide->refresh()->title);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_guides_are_rejected(array $overrides, string $error): void
    {
        $this->actingAs($this->createVerifiedAdminUser())
            ->post(route('bitcraft.guides.store'), [...$this->payload(), ...$overrides])
            ->assertSessionHasErrors($error);

        $this->assertDatabaseCount('bitcraft_guides', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing title' => [['title' => ''], 'title'],
            'long title' => [['title' => str_repeat('a', 256)], 'title'],
            'long summary' => [['summary' => str_repeat('a', 1001)], 'summary'],
            'long category' => [['category' => str_repeat('a', 101)], 'category'],
            'plain HTML' => [['content' => '<p>Guide</p>'], 'content'],
            'not a document' => [['content' => ['type' => 'paragraph', 'content' => []]], 'content.type'],
            'empty content' => [['content' => ['type' => 'doc', 'content' => []]], 'content.content'],
            'blank paragraph' => [['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]], 'content'],
            'invalid node' => [['content' => ['type' => 'doc', 'content' => [['text' => 'Hello']]]], 'content.content.0.type'],
            'invalid publication' => [['is_published' => 'invalid'], 'is_published'],
        ];
    }

    public function test_invalid_update_leaves_the_guide_unchanged(): void
    {
        $guide = BitcraftGuide::factory()->published()->create();
        $original = $guide->refresh()->getAttributes();

        $this->actingAs($this->createVerifiedAdminUser())
            ->put(route('bitcraft.guides.update', $guide), [...$this->payload(), 'content' => null])
            ->assertSessionHasErrors('content');

        $this->assertSame($original, $guide->refresh()->getAttributes());
    }

    public function test_search_remains_scoped_to_published_guides_and_results_are_paginated(): void
    {
        $author = User::factory()->create();
        BitcraftGuide::factory()->published()->count(16)->create([
            'user_id' => $author->id, 'title' => 'Fishing guide', 'category' => 'Fishing',
        ]);
        BitcraftGuide::factory()->create(['user_id' => $author->id, 'summary' => 'Fishing secret draft']);

        $this->actingAs($this->reader())->get(route('bitcraft.guides.index', ['q' => 'fIsHiNg']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('guides.data', 15)->where('guides.total', 16)->where('guides.last_page', 2)
            );
        $this->get(route('bitcraft.guides.index', ['q' => 'fIsHiNg', 'page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('guides.data', 1));
    }

    public function test_deleting_the_author_preserves_the_guide(): void
    {
        $guide = BitcraftGuide::factory()->published()->create();
        $guide->author->delete();

        $this->assertNull($guide->refresh()->user_id);
        $this->actingAs($this->reader())->get(route('bitcraft.guides.show', $guide))->assertOk();
    }

    private function reader(): User
    {
        $user = User::factory()->create();
        Role::findOrCreate(User::ROLE_BITCRAFT, 'web');
        $user->assignRole(User::ROLE_BITCRAFT);

        return $user;
    }

    private function payload(): array
    {
        return [
            'title' => 'Starting your first crafting project',
            'summary' => 'A beginner crafting guide.',
            'category' => 'Crafting',
            'content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Collect your materials first.']],
                ]],
            ],
            'is_published' => false,
        ];
    }

    private function itemCard(): array
    {
        return ['type' => 'bitcraftItem', 'attrs' => [
            'id' => 123,
            'kind' => 'item',
            'name' => 'Crushed Copper Ore',
            'category' => 'Ore',
            'tier' => 2,
            'rarity' => 'Common',
            'iconAssetName' => 'Items/CrushedCopperOre',
        ]];
    }
}
