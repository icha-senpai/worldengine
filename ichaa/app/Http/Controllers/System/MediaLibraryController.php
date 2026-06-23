<?php

namespace App\Http\Controllers\System;

use App\Domain\Identity\Models\MediaReference;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MediaLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'media_type' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $query = MediaReference::query()
            ->with(['entity:id,name'])
            ->whereNull('deleted_at')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderByDesc('id');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('file_path', 'like', "%{$search}%");
            });
        }

        if (! empty($validated['media_type'])) {
            $query->where('media_type', $validated['media_type']);
        }

        if (! empty($validated['purpose'])) {
            $query->where('purpose', $validated['purpose']);
        }

        $perPage = (int) ($validated['per_page'] ?? 18);
        $records = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => $records->getCollection()->map(fn (MediaReference $media) => $this->presentMedia($media))->values(),
            'meta' => [
                'filters' => [
                    'search' => $validated['search'] ?? '',
                    'media_type' => $validated['media_type'] ?? '',
                    'purpose' => $validated['purpose'] ?? '',
                ],
                'options' => [
                    'media_types' => MediaReference::query()
                        ->whereNull('deleted_at')
                        ->whereNotNull('media_type')
                        ->distinct()
                        ->orderBy('media_type')
                        ->pluck('media_type')
                        ->values(),
                    'purposes' => MediaReference::query()
                        ->whereNull('deleted_at')
                        ->whereNotNull('purpose')
                        ->distinct()
                        ->orderBy('purpose')
                        ->pluck('purpose')
                        ->values(),
                ],
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                ],
            ],
        ]);
    }

    public function asset(MediaReference $mediaReference)
    {
        if ($mediaReference->isExternalLink()) {
            return redirect()->away($mediaReference->url);
        }

        abort_unless($mediaReference->isLocalFile(), 404);

        $path = $mediaReference->file_path;

        abort_unless(is_string($path) && $path !== '' && is_file($path) && is_readable($path), 404);

        $mimeType = $mediaReference->mime_type ?: mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mimeType,
        ]);
    }

    private function presentMedia(MediaReference $media): array
    {
        $attachment = $media->attachmentTarget();
        return [
            'id' => $media->getKey(),
            'title' => $media->title ?: ($media->file_name ?: 'Untitled media'),
            'description' => $media->description,
            'media_type' => $media->media_type,
            'purpose' => $media->purpose,
            'is_primary' => (bool) $media->is_primary,
            'source_kind' => $media->isManagedUpload()
                ? 'upload'
                : ($media->isExternalLink() ? 'external' : ($media->isLocalFile() ? 'local' : 'unknown')),
            'preview_url' => $this->mediaUrl($media),
            'insert_url' => $this->mediaUrl($media),
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'dimensions' => [
                'width' => $media->widthPx(),
                'height' => $media->heightPx(),
            ],
            'attachment' => [
                'type' => $attachment['type'],
                'id' => $attachment['id'],
                'label' => $this->attachmentLabel($media, $attachment),
            ],
        ];
    }

    private function mediaUrl(MediaReference $media): ?string
    {
        if ($media->isExternalLink()) {
            return $media->url;
        }

        if ($media->isLocalFile()) {
            return route('media-library.asset', $media);
        }

        return null;
    }

    private function attachmentLabel(MediaReference $media, array $attachment): string
    {
        if ($attachment['type'] === 'entity' && $media->relationLoaded('entity') && $media->entity) {
            return $media->entity->name;
        }

        if (! $attachment['id']) {
            return 'Unattached';
        }

        return Str::of($attachment['type'])
            ->replace('_', ' ')
            ->title()
            ->append(' #'.$attachment['id'])
            ->toString();
    }
}
