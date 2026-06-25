<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Services\MediaReferenceUploadService;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Production\Models\Meta;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class MediaReferenceController extends Controller
{
    private const ATTACHMENT_TYPES = [
        'entity',
        'group_relationship',
        'collection',
        'meta',
        'timeline_entry',
        'concurrency_group',
        'source_canon_reference',
    ];

    private const SOURCE_KINDS = [
        'external',
        'local',
        'upload',
    ];

    public function __construct(
        private readonly MediaReferenceUploadService $uploads,
    ) {}

    public function index(Request $request): Response
    {
        return $this->indexPage($request);
    }

    public function create(): Response
    {
        return $this->indexPage(request(), [
            'createDrawer' => $this->createFormProps(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateMedia($request);

        $media = MediaReference::create($this->payload($validated, $request));

        return $this->to('media-references.show', [$media], "Media '{$media->title}' created.");
    }

    public function show(MediaReference $mediaReference): Response
    {
        return $this->showPage($mediaReference);
    }

    public function edit(MediaReference $mediaReference): Response
    {
        return $this->showPage($mediaReference, [
            'editDrawer' => array_merge(
                $this->formProps(),
                [
                    'media' => $this->editPayload($mediaReference),
                ],
            ),
        ]);
    }

    public function update(Request $request, MediaReference $mediaReference)
    {
        $validated = $this->validateMedia($request, $mediaReference);

        $mediaReference->update($this->payload($validated, $request, $mediaReference));

        return $this->to('media-references.show', [$mediaReference], 'Media updated.');
    }

    public function destroy(MediaReference $mediaReference)
    {
        $mediaReference->delete();

        return $this->to('media-references.index', [], 'Media deleted.');
    }

    private function validateMedia(Request $request, ?MediaReference $existing = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'media_type' => ['required', Rule::in(MediaReference::MEDIA_TYPES)],
            'purpose' => ['required', Rule::in(MediaReference::PURPOSES)],
            'source_kind' => ['required', Rule::in(self::SOURCE_KINDS)],
            'attachment_type' => ['required', Rule::in(self::ATTACHMENT_TYPES)],
            'attachment_id' => ['required', 'integer', 'min:1'],
            'url' => ['nullable', 'url'],
            'file_path' => ['nullable', 'string'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_extension' => ['nullable', 'string', 'max:50'],
            'file_size_bytes' => ['nullable', 'integer', 'min:0'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'width_px' => ['nullable', 'integer', 'min:0'],
            'height_px' => ['nullable', 'integer', 'min:0'],
            'upload_file' => ['nullable', 'file', 'max:51200'],
            'sort_order' => ['nullable', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'visibility' => ['required', Rule::in(VisibilityLevel::ALL)],
            'content_classification' => ['required', Rule::in(ContentClassification::ALL)],
        ]);

        if ($validated['source_kind'] === 'external') {
            $request->validate([
                'url' => ['required', 'url'],
            ]);
        }

        if ($validated['source_kind'] === 'local') {
            $request->validate([
                'file_path' => ['required', 'string'],
            ]);
        }

        if (
            $validated['source_kind'] === 'upload'
            && ! $request->hasFile('upload_file')
            && ! ($existing?->isManagedUpload() ?? false)
        ) {
            throw ValidationException::withMessages([
                'upload_file' => 'Upload a file for this media reference.',
            ]);
        }

        $targetExists = $this->targetQuery($validated['attachment_type'])
            ->whereKey($validated['attachment_id'])
            ->exists();

        if (! $targetExists) {
            throw ValidationException::withMessages([
                'attachment_id' => 'Select a valid attachment target.',
            ]);
        }

        return $validated;
    }

    private function payload(array $validated, Request $request, ?MediaReference $existing = null): array
    {
        $payload = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'media_type' => $validated['media_type'],
            'purpose' => $validated['purpose'],
            'file_path' => null,
            'url' => null,
            'file_name' => null,
            'file_extension' => null,
            'file_size_bytes' => null,
            'mime_type' => null,
            'width_px' => null,
            'height_px' => null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'visibility' => $validated['visibility'],
            'content_classification' => $validated['content_classification'],
        ];

        if ($validated['source_kind'] === 'external') {
            $payload['url'] = $validated['url'] ?? null;
            $payload['file_name'] = $validated['file_name'] ?? null;
            $payload['file_extension'] = $validated['file_extension'] ?? null;
            $payload['file_size_bytes'] = $validated['file_size_bytes'] ?? null;
            $payload['mime_type'] = $validated['mime_type'] ?? null;
            $payload['width_px'] = $validated['width_px'] ?? null;
            $payload['height_px'] = $validated['height_px'] ?? null;
        }

        if ($validated['source_kind'] === 'local') {
            $payload['file_path'] = $validated['file_path'] ?? null;
            $payload['file_name'] = $validated['file_name'] ?? null;
            $payload['file_extension'] = $validated['file_extension'] ?? null;
            $payload['file_size_bytes'] = $validated['file_size_bytes'] ?? null;
            $payload['mime_type'] = $validated['mime_type'] ?? null;
            $payload['width_px'] = $validated['width_px'] ?? null;
            $payload['height_px'] = $validated['height_px'] ?? null;
        }

        if ($validated['source_kind'] === 'upload') {
            $payload = array_merge($payload, $this->uploadedFilePayload(
                $request->file('upload_file'),
                $existing,
            ));
        }

        foreach (MediaReference::ATTACHMENT_FIELDS as $field) {
            $payload[$field] = null;
        }

        $payload[MediaReference::ATTACHMENT_FIELDS[$validated['attachment_type']]] = $validated['attachment_id'];

        return $payload;
    }

    private function uploadedFilePayload(?UploadedFile $file, ?MediaReference $existing = null): array
    {
        if (! $file && $existing?->isManagedUpload()) {
            return $this->uploads->existingManagedUploadPayload($existing);
        }

        if (! $file) {
            return [];
        }

        return $this->uploads->payloadFromUploadedFile($file);
    }

    private function formProps(): array
    {
        return [
            'attachmentTypes' => self::ATTACHMENT_TYPES,
            'attachmentTargets' => $this->attachmentTargets(),
            'mediaTypes' => MediaReference::MEDIA_TYPES,
            'purposes' => MediaReference::PURPOSES,
            'sourceKinds' => self::SOURCE_KINDS,
            'visibilityLevels' => VisibilityLevel::ALL,
            'contentClassifications' => ContentClassification::ALL,
        ];
    }

    private function attachmentTargets(): array
    {
        return [
            'entity' => Entity::query()
                ->select('id', 'name', 'entity_type')
                ->orderBy('name')
                ->get(),
            'group_relationship' => GroupRelationship::query()
                ->select('id', 'name', 'relationship_type')
                ->orderBy('name')
                ->get(),
            'collection' => Collection::query()
                ->select('id', 'name', 'collection_type')
                ->orderBy('name')
                ->get(),
            'meta' => Meta::query()
                ->select('id', 'title', 'category')
                ->orderBy('title')
                ->get(),
            'timeline_entry' => Timeline::query()
                ->with(['timeline:id,name', 'eventEntity:id,name'])
                ->select('id', 'timeline_id', 'event_entity_id', 'entry_label', 'au_date')
                ->orderBy('id')
                ->get(),
            'concurrency_group' => ConcurrencyGroup::query()
                ->select('id', 'name', 'au_date')
                ->orderBy('name')
                ->get(),
            'source_canon_reference' => SourceCanonReference::query()
                ->select('id', 'title', 'universe')
                ->orderBy('title')
                ->get(),
        ];
    }

    private function targetQuery(string $attachmentType)
    {
        return match ($attachmentType) {
            'entity' => Entity::query(),
            'group_relationship' => GroupRelationship::query(),
            'collection' => Collection::query(),
            'meta' => Meta::query(),
            'timeline_entry' => Timeline::query(),
            'concurrency_group' => ConcurrencyGroup::query(),
            'source_canon_reference' => SourceCanonReference::query(),
        };
    }

    private function showPayload(MediaReference $media): array
    {
        $attachment = $media->attachmentTarget();
        $attachmentLabel = match ($attachment['type']) {
            'entity' => $media->entity?->name,
            'group_relationship' => $media->groupRelationship?->name,
            'collection' => $media->collection?->name,
            'meta' => $media->meta?->title,
            'timeline_entry' => $media->timelineEntry?->entry_label ?: $media->timelineEntry?->eventEntity?->name,
            'concurrency_group' => $media->concurrencyGroup?->name,
            'source_canon_reference' => $media->sourceCanonReference?->title,
            default => null,
        };

        $attachmentHref = match ($attachment['type']) {
            'entity' => $media->entity ? route('entities.show', $media->entity) : null,
            'group_relationship' => $media->groupRelationship ? route('group-relationships.show', $media->groupRelationship) : null,
            'collection' => $media->collection ? route('collections.show', $media->collection) : null,
            'meta' => $media->meta ? route('meta.show', $media->meta) : null,
            'timeline_entry' => $media->timelineEntry && $media->timelineEntry->timeline_id ? route('timelines.show', $media->timelineEntry->timeline_id) : null,
            'concurrency_group' => $media->concurrencyGroup ? route('concurrency-groups.show', $media->concurrencyGroup) : null,
            'source_canon_reference' => $media->sourceCanonReference ? route('canon-references.show', $media->sourceCanonReference) : null,
            default => null,
        };

        return [
            'id' => $media->id,
            'title' => $media->title,
            'description' => $media->description,
            'media_type' => $media->media_type,
            'purpose' => $media->purpose,
            'preview_url' => $media->isExternalLink()
                ? $media->url
                : route('media-library.asset', $media),
            'source_kind' => $media->isManagedUpload()
                ? 'upload'
                : ($media->isLocalFile() ? 'local' : 'external'),
            'attachment' => [
                'type' => $attachment['type'],
                'label' => $attachmentLabel,
                'href' => $attachmentHref,
            ],
            'file_path' => $media->file_path,
            'url' => $media->url,
            'file_name' => $media->file_name,
            'file_extension' => $media->file_extension,
            'file_size_bytes' => $media->file_size_bytes,
            'mime_type' => $media->mime_type,
            'width_px' => $media->width_px,
            'height_px' => $media->height_px,
            'sort_order' => $media->sort_order,
            'is_primary' => $media->is_primary,
            'visibility' => $media->visibility,
            'content_classification' => $media->content_classification,
        ];
    }

    private function editPayload(MediaReference $mediaReference): array
    {
        return array_merge(
            $mediaReference->toArray(),
            [
                'attachment_type' => $mediaReference->attachmentTarget()['type'] !== 'unknown'
                    ? $mediaReference->attachmentTarget()['type']
                    : '',
                'attachment_id' => $mediaReference->attachmentTarget()['id'] ?? '',
                'source_kind' => $mediaReference->isManagedUpload()
                    ? 'upload'
                    : ($mediaReference->isLocalFile() ? 'local' : 'external'),
            ],
        );
    }



    private function indexPage(Request $request, array $props = []): Response
    {
        $query = MediaReference::query()
            ->with([
                'entity:id,name',
                'groupRelationship:id,name',
                'collection:id,name',
                'meta:id,title',
                'timelineEntry:id,entry_label,event_entity_id',
                'timelineEntry.eventEntity:id,name',
                'concurrencyGroup:id,name',
                'sourceCanonReference:id,title',
            ])
            ->ordered()
            ->latest('id');

        if ($request->filled('media_type')) {
            $query->where('media_type', $request->string('media_type')->toString());
        }

        if ($request->filled('purpose')) {
            $query->where('purpose', $request->string('purpose')->toString());
        }

        if ($request->filled('attachment_type') && in_array($request->attachment_type, self::ATTACHMENT_TYPES, true)) {
            $query->whereNotNull(MediaReference::ATTACHMENT_FIELDS[$request->attachment_type]);
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->string('visibility')->toString());
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('file_name', 'like', "%{$term}%")
                    ->orWhere('url', 'like', "%{$term}%")
                    ->orWhere('file_path', 'like', "%{$term}%");
            });
        }

                return $this->page('Identity/MediaReferences/Index', array_merge([
            'media' => $query->paginate(30)->withQueryString(),
            'filters' => $request->only(['search', 'media_type', 'purpose', 'attachment_type', 'visibility']),
            'mediaTypes' => MediaReference::MEDIA_TYPES,
            'purposes' => MediaReference::PURPOSES,
            'attachmentTypes' => self::ATTACHMENT_TYPES,
        ], $props));
    
    }

    private function createFormProps(): array
    {
        return $this->formProps();
    }

    private function showPage(MediaReference $mediaReference, array $props = []): Response
    {
        $mediaReference->load([
            'entity:id,name',
            'groupRelationship:id,name',
            'collection:id,name',
            'meta:id,title',
            'timelineEntry:id,entry_label,event_entity_id',
            'timelineEntry.eventEntity:id,name',
            'concurrencyGroup:id,name',
            'sourceCanonReference:id,title',
        ]);

        return $this->page('Identity/MediaReferences/Show', array_merge([
            'media' => $this->showPayload($mediaReference),
        ], $props));
    }
}
