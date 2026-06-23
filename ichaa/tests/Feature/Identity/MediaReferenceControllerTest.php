<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaReferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_create_external_media_references(): void
    {
        $user = User::factory()->create();
        $entity = Entity::factory()->create(['name' => 'Mirror Library']);

        $response = $this->actingAs($user)->post(route('media-references.store'), [
            'title' => 'Mirror Library Exterior',
            'description' => 'Reference exterior shot.',
            'media_type' => 'image',
            'purpose' => 'reference',
            'source_kind' => 'external',
            'attachment_type' => 'entity',
            'attachment_id' => $entity->id,
            'url' => 'https://example.com/mirror-library.png',
            'file_name' => 'mirror-library.png',
            'file_extension' => 'png',
            'mime_type' => 'image/png',
            'width_px' => 1600,
            'height_px' => 900,
            'sort_order' => 3,
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $media = MediaReference::query()->latest('id')->first();

        $response->assertRedirect(route('media-references.show', $media));

        $this->assertNotNull($media);
        $this->assertSame($entity->id, $media->entity_id);
        $this->assertNull($media->collection_id);
        $this->assertSame('https://example.com/mirror-library.png', $media->url);
        $this->assertNull($media->file_path);
        $this->assertSame(1600, $media->width_px);
        $this->assertSame(900, $media->height_px);
    }

    public function test_users_can_update_local_media_references(): void
    {
        $user = User::factory()->create();
        $entity = Entity::factory()->create();
        $media = MediaReference::query()->create([
            'entity_id' => $entity->id,
            'title' => 'Original Media',
            'description' => 'Original description',
            'media_type' => 'image',
            'purpose' => 'reference',
            'url' => 'https://example.com/original.png',
            'sort_order' => 1,
            'is_primary' => false,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $response = $this->actingAs($user)->put(route('media-references.update', $media), [
            'title' => 'Archive Blueprint',
            'description' => 'Updated local file reference.',
            'media_type' => 'document',
            'purpose' => 'map',
            'source_kind' => 'local',
            'attachment_type' => 'entity',
            'attachment_id' => $entity->id,
            'file_path' => 'C:\\refs\\archive-blueprint.pdf',
            'file_name' => 'archive-blueprint.pdf',
            'file_extension' => 'pdf',
            'file_size_bytes' => 2048,
            'mime_type' => 'application/pdf',
            'width_px' => '',
            'height_px' => '',
            'sort_order' => 7,
            'is_primary' => false,
            'visibility' => 'author_only',
            'content_classification' => 'author_only',
        ]);

        $response->assertRedirect(route('media-references.show', $media));

        $media->refresh();

        $this->assertSame('Archive Blueprint', $media->title);
        $this->assertSame('C:\\refs\\archive-blueprint.pdf', $media->file_path);
        $this->assertNull($media->url);
        $this->assertSame(2048, $media->file_size_bytes);
        $this->assertSame('author_only', $media->visibility);
        $this->assertSame('author_only', $media->content_classification);
    }

    public function test_users_can_create_uploaded_media_references(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $entity = Entity::factory()->create(['name' => 'Archive Vault']);
        $file = UploadedFile::fake()->image('archive-vault.png', 1200, 800);

        $response = $this->actingAs($user)->post(route('media-references.store'), [
            'title' => 'Archive Vault Upload',
            'description' => 'Uploaded reference image.',
            'media_type' => 'image',
            'purpose' => 'reference',
            'source_kind' => 'upload',
            'attachment_type' => 'entity',
            'attachment_id' => $entity->id,
            'upload_file' => $file,
            'sort_order' => 4,
            'is_primary' => true,
            'visibility' => 'private',
            'content_classification' => 'restricted',
        ]);

        $media = MediaReference::query()->latest('id')->first();

        $response->assertRedirect(route('media-references.show', $media));

        $this->assertNotNull($media);
        $this->assertTrue($media->isManagedUpload());
        $this->assertNull($media->url);
        $this->assertSame('archive-vault.png', $media->file_name);
        $this->assertSame('png', $media->file_extension);
        $this->assertSame('image/png', $media->mime_type);
        $this->assertSame(1200, $media->width_px);
        $this->assertSame(800, $media->height_px);
        $this->assertNotNull($media->file_size_bytes);

        $relativePath = str_replace(Storage::disk('public')->path(''), '', $media->file_path);
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        Storage::disk('public')->assertExists($relativePath);
    }
}
