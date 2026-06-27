<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\Models\NotionNote;
use App\Http\Controllers\Controller;
use App\Support\Web\DataverseWebResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class NotionNoteController extends Controller
{
    public function __construct(
        private readonly DataverseWebResourceRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $query = NotionNote::query()->latest('id');

        if ($request->filled('sync_resource')) {
            $query->where('sync_resource', $request->string('sync_resource')->value());
        }

        $term = (string) $request->string('q')->trim();

        if ($term !== '') {
            $query->where(function (Builder $inner) use ($term) {
                $inner
                    ->where('sync_resource', 'like', "%{$term}%")
                    ->orWhere('notion_page_id', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%")
                    ->orWhere('noteable_type', 'like', "%{$term}%")
                    ->orWhere('noteable_id', 'like', "%{$term}%");
            });
        }

        $notes = $query->paginate(40)->withQueryString();

        $items = collect($notes->items())
            ->map(fn (NotionNote $note) => [
                'id' => $note->id,
                'href' => route('admin.notion-notes.show', $note),
                'title' => $this->registry->linkForModel($note->noteable_type, $note->noteable_id)['label'] ?? ('Notion Page '.$note->notion_page_id),
                'badges' => [
                    ['label' => 'Resource', 'value' => $this->label($note->sync_resource)],
                ],
                'meta' => collect([
                    ['label' => 'Page ID', 'value' => $note->notion_page_id],
                    ['label' => 'Model', 'value' => class_basename((string) $note->noteable_type)],
                    ['label' => 'Record', 'value' => $note->noteable_id ? '#'.$note->noteable_id : null],
                    ['label' => 'Last Synced', 'value' => optional($note->last_synced_at)->format('Y-m-d H:i')],
                ])->filter(fn (array $pair) => filled($pair['value']))->values()->all(),
            ])
            ->values();

        return $this->page('Admin/NotionNotes/Index', [
            'notes' => $notes,
            'items' => $items,
            'filters' => $request->only(['q', 'sync_resource']),
            'filterFields' => [
                ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search notes...'],
                ['key' => 'sync_resource', 'type' => 'text', 'placeholder' => 'Filter by sync resource...'],
            ],
        ]);
    }

    public function show(NotionNote $notionNote): Response
    {
        return $this->page('Admin/NotionNotes/Show', [
            'note' => [
                'id' => $notionNote->id,
                'sync_resource' => $notionNote->sync_resource,
                'notion_page_id' => $notionNote->notion_page_id,
                'noteable_type' => $notionNote->noteable_type,
                'noteable_id' => $notionNote->noteable_id,
                'content' => $notionNote->content,
                'content_hash' => $notionNote->content_hash,
                'notion_last_edited_at' => optional($notionNote->notion_last_edited_at)->toIso8601String(),
                'last_synced_at' => optional($notionNote->last_synced_at)->toIso8601String(),
                'record_link' => $this->registry->linkForModel($notionNote->noteable_type, $notionNote->noteable_id),
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
