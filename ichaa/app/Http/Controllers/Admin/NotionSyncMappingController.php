<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\Models\NotionSyncMapping;
use App\Http\Controllers\Controller;
use App\Support\Web\DataverseWebResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class NotionSyncMappingController extends Controller
{
    public function __construct(
        private readonly DataverseWebResourceRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $query = NotionSyncMapping::query()->latest('id');

        if ($request->filled('sync_resource')) {
            $query->where('sync_resource', $request->string('sync_resource')->value());
        }

        $term = (string) $request->string('q')->trim();

        if ($term !== '') {
            $query->where(function (Builder $inner) use ($term) {
                $inner
                    ->where('sync_resource', 'like', "%{$term}%")
                    ->orWhere('notion_page_id', 'like', "%{$term}%")
                    ->orWhere('notion_parent_database_id', 'like', "%{$term}%")
                    ->orWhere('local_model_type', 'like', "%{$term}%")
                    ->orWhere('local_model_id', 'like', "%{$term}%")
                    ->orWhere('last_payload_hash', 'like', "%{$term}%");
            });
        }

        $mappings = $query->paginate(40)->withQueryString();

        $items = collect($mappings->items())
            ->map(fn (NotionSyncMapping $mapping) => [
                'id' => $mapping->id,
                'href' => route('admin.notion-sync-mappings.show', $mapping),
                'title' => $this->registry->linkForModel($mapping->local_model_type, $mapping->local_model_id)['label'] ?? ('Notion Page '.$mapping->notion_page_id),
                'badges' => [
                    ['label' => 'Resource', 'value' => $this->label($mapping->sync_resource)],
                ],
                'meta' => collect([
                    ['label' => 'Page ID', 'value' => $mapping->notion_page_id],
                    ['label' => 'Database', 'value' => $mapping->notion_parent_database_id],
                    ['label' => 'Model', 'value' => class_basename((string) $mapping->local_model_type)],
                    ['label' => 'Record', 'value' => $mapping->local_model_id ? '#'.$mapping->local_model_id : null],
                ])->filter(fn (array $pair) => filled($pair['value']))->values()->all(),
            ])
            ->values();

        return $this->page('Admin/NotionSyncMappings/Index', [
            'mappings' => $mappings,
            'items' => $items,
            'filters' => $request->only(['q', 'sync_resource']),
            'filterFields' => [
                ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search mappings...'],
                ['key' => 'sync_resource', 'type' => 'text', 'placeholder' => 'Filter by sync resource...'],
            ],
        ]);
    }

    public function show(NotionSyncMapping $notionSyncMapping): Response
    {
        return $this->page('Admin/NotionSyncMappings/Show', [
            'mapping' => [
                'id' => $notionSyncMapping->id,
                'sync_resource' => $notionSyncMapping->sync_resource,
                'notion_page_id' => $notionSyncMapping->notion_page_id,
                'notion_parent_database_id' => $notionSyncMapping->notion_parent_database_id,
                'local_model_type' => $notionSyncMapping->local_model_type,
                'local_model_id' => $notionSyncMapping->local_model_id,
                'notion_last_edited_at' => optional($notionSyncMapping->notion_last_edited_at)->toIso8601String(),
                'last_synced_at' => optional($notionSyncMapping->last_synced_at)->toIso8601String(),
                'last_payload_hash' => $notionSyncMapping->last_payload_hash,
                'record_link' => $this->registry->linkForModel($notionSyncMapping->local_model_type, $notionSyncMapping->local_model_id),
            ],
        ]);
    }

    private function label(?string $value): string
    {
        return $value
            ? Str::of($value)->replace(['_', '-'], ' ')->title()->value()
            : '—';
    }
}
