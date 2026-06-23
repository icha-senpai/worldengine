<?php

namespace Tests\Feature\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_browse_media_library_items(): void
    {
        $user = User::factory()->create();
        $entity = Entity::factory()->create(['name' => 'Seraphine Morbraith']);

        MediaReference::query()->create([
            'entity_id' => $entity->id,
            'title' => 'Seraphine Portrait',
            'description' => 'Primary portrait reference',
            'media_type' => 'image',
            'purpose' => 'portrait',
            'url' => 'https://example.com/seraphine-portrait.png',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        MediaReference::query()->create([
            'entity_id' => $entity->id,
            'title' => 'Mirror Hall Notes',
            'media_type' => 'document',
            'purpose' => 'reference',
            'url' => 'https://example.com/mirror-hall.pdf',
            'sort_order' => 2,
            'is_primary' => false,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $response = $this->actingAs($user)->getJson(route('media-library.index', [
            'search' => 'Seraphine',
            'media_type' => 'image',
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Seraphine Portrait')
            ->assertJsonPath('data.0.preview_url', 'https://example.com/seraphine-portrait.png')
            ->assertJsonPath('data.0.attachment.label', 'Seraphine Morbraith')
            ->assertJsonPath('meta.filters.media_type', 'image');
    }

    public function test_authenticated_users_can_preview_local_media_files(): void
    {
        $user = User::factory()->create();
        $entity = Entity::factory()->create();
        $directory = storage_path('framework/testing/media-library');

        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.'local-media-preview.txt';
        File::put($path, 'local preview body');

        $media = MediaReference::query()->create([
            'entity_id' => $entity->id,
            'title' => 'Local Preview',
            'media_type' => 'document',
            'purpose' => 'reference',
            'file_path' => $path,
            'file_name' => 'local-media-preview.txt',
            'mime_type' => 'text/plain',
            'sort_order' => 1,
            'is_primary' => false,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $response = $this->actingAs($user)->get(route('media-library.asset', $media));

        $response->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8');

        $this->assertSame($path, $response->baseResponse->getFile()->getPathname());
    }

    public function test_media_library_marks_managed_uploads_with_upload_source_kind(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $entity = Entity::factory()->create(['name' => 'Grey Archive']);

        Storage::disk('public')->put(
            'media-library/grey-archive-map.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9l9o8AAAAASUVORK5CYII=', true),
        );

        MediaReference::query()->create([
            'entity_id' => $entity->id,
            'title' => 'Grey Archive Map',
            'media_type' => 'image',
            'purpose' => 'map',
            'file_path' => Storage::disk('public')->path('media-library/grey-archive-map.png'),
            'file_name' => 'grey-archive-map.png',
            'mime_type' => 'image/png',
            'sort_order' => 1,
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $response = $this->actingAs($user)->getJson(route('media-library.index', [
            'search' => 'Grey Archive',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.0.source_kind', 'upload');
    }
}
