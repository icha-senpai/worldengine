<?php

namespace App\Http\Controllers\Admin;

use App\Domain\System\Models\Revision;
use App\Domain\System\Services\RevisionService;
use App\Http\Controllers\Controller;
use App\Support\Web\DataverseWebResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;
use Throwable;

class RevisionController extends Controller
{
    public function __construct(
        private readonly RevisionService $revisions,
        private readonly DataverseWebResourceRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $query = Revision::query()
            ->with(['actor:id,name'])
            ->latest('id');

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->string('resource_type')->value());
        }

        if ($request->filled('resource_id')) {
            $query->where('resource_id', (string) $request->string('resource_id')->value());
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->value());
        }

        $term = (string) $request->string('q')->trim();

        if ($term !== '') {
            $query->where(function (Builder $inner) use ($term) {
                $inner
                    ->where('reason', 'like', "%{$term}%")
                    ->orWhere('source', 'like', "%{$term}%")
                    ->orWhere('token_name', 'like', "%{$term}%")
                    ->orWhere('resource_type', 'like', "%{$term}%")
                    ->orWhere('resource_id', 'like', "%{$term}%")
                    ->orWhereHas('actor', fn (Builder $actor) => $actor->where('name', 'like', "%{$term}%"));
            });
        }

        $revisions = $query->paginate(40)->withQueryString();

        $items = collect($revisions->items())
            ->map(fn (Revision $revision) => $this->revisionListItem($revision))
            ->values();

        $compareOptions = collect($revisions->items())
            ->map(fn (Revision $revision) => [
                'value' => $revision->id,
                'label' => sprintf(
                    '#%d · %s · %s',
                    $revision->id,
                    $this->label($revision->action),
                    optional($revision->created_at)->format('Y-m-d H:i') ?? 'unknown time',
                ),
            ])
            ->values();

        return $this->page('Admin/Revisions/Index', [
            'revisions' => $revisions,
            'items' => $items,
            'filters' => $request->only(['q', 'resource_type', 'resource_id', 'action']),
            'filterFields' => [
                ['key' => 'q', 'type' => 'text', 'placeholder' => 'Search revisions...'],
                ['key' => 'resource_type', 'type' => 'text', 'placeholder' => 'Filter by resource type...'],
                ['key' => 'resource_id', 'type' => 'text', 'placeholder' => 'Filter by record id...'],
                ['key' => 'action', 'type' => 'text', 'placeholder' => 'Filter by action...'],
            ],
            'compareOptions' => $compareOptions,
        ]);
    }

    public function show(Revision $revision): Response
    {
        $revision->load(['actor:id,name', 'restoredFrom:id,resource_type,resource_id,action']);

        $recordLink = $this->registry->linkForResourceType($revision->resource_type, $revision->resource_id);
        $compareOptions = Revision::query()
            ->where('resource_type', $revision->resource_type)
            ->where('resource_id', $revision->resource_id)
            ->whereKeyNot($revision->id)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'action', 'created_at'])
            ->map(fn (Revision $candidate) => [
                'value' => $candidate->id,
                'label' => sprintf(
                    '#%d · %s · %s',
                    $candidate->id,
                    $this->label($candidate->action),
                    optional($candidate->created_at)->format('Y-m-d H:i') ?? 'unknown time',
                ),
            ])
            ->values();

        return $this->page('Admin/Revisions/Show', [
            'revision' => [
                'id' => $revision->id,
                'resource_type' => $revision->resource_type,
                'resource_id' => $revision->resource_id,
                'action' => $revision->action,
                'reason' => $revision->reason,
                'source' => $revision->source,
                'token_name' => $revision->token_name,
                'base_revision_id' => $revision->base_revision_id,
                'restored_from_revision_id' => $revision->restored_from_revision_id,
                'created_at' => optional($revision->created_at)->toIso8601String(),
                'actor_name' => $revision->actor?->name,
                'record_link' => $recordLink,
                'before_payload' => $revision->before_payload,
                'after_payload' => $revision->after_payload,
                'diff_payload' => $revision->diff_payload,
            ],
            'restoreHref' => route('admin.revisions.restore', $revision),
            'compareOptions' => $compareOptions,
            'compareHref' => route('admin.revisions.compare'),
        ]);
    }

    public function compare(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'left' => ['required', 'integer'],
            'right' => ['required', 'integer'],
        ]);

        $left = Revision::query()->with('actor:id,name')->findOrFail($validated['left']);
        $right = Revision::query()->with('actor:id,name')->findOrFail($validated['right']);

        if ($left->resource_type !== $right->resource_type || (string) $left->resource_id !== (string) $right->resource_id) {
            return $this->to('admin.revisions.index', [], 'Compare the same resource record on both sides.');
        }

        $recordLink = $this->registry->linkForResourceType($left->resource_type, $left->resource_id);
        $diff = $this->diffPayload($left->after_payload ?? [], $right->after_payload ?? []);

        return $this->page('Admin/Revisions/Compare', [
            'comparison' => [
                'resource_type' => $left->resource_type,
                'resource_id' => $left->resource_id,
                'record_link' => $recordLink,
                'left' => $this->comparisonRevision($left),
                'right' => $this->comparisonRevision($right),
                'before' => $left->after_payload,
                'after' => $right->after_payload,
                'diff' => $diff,
            ],
        ]);
    }

    public function restore(Request $request, Revision $revision): RedirectResponse
    {
        try {
            $record = $this->registry->find($revision->resource_type, $revision->resource_id, true);

            abort_unless($record, 404);

            $before = $record->attributesToArray();
            $baseRevisionId = $this->revisions->currentRevisionId($revision->resource_type, $record->getKey());

            $request->merge([
                'meta' => [
                    'base_revision_id' => $baseRevisionId,
                    'reason' => $request->string('reason')->value() ?: 'Restored from the admin revision surface.',
                    'source' => 'web',
                ],
            ]);

            $restored = $this->revisions->restoreModel($record, $revision);
            $recorded = $this->revisions->record(
                $revision->resource_type,
                $restored,
                'restore_revision',
                $before,
                $restored->attributesToArray(),
                $request,
                $revision->id,
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.revisions.show', $recorded)
            ->with('success', 'Revision restored.');
    }

    private function revisionListItem(Revision $revision): array
    {
        $recordLink = $this->registry->linkForResourceType($revision->resource_type, $revision->resource_id);

        return [
            'id' => $revision->id,
            'href' => route('admin.revisions.show', $revision),
            'title' => $recordLink['label'] ?? $this->registry->label($revision->resource_type).' #'.$revision->resource_id,
            'badges' => [
                ['label' => 'Resource', 'value' => $this->registry->label($revision->resource_type)],
                ['label' => 'Action', 'value' => $this->label($revision->action)],
            ],
            'meta' => collect([
                ['label' => 'Revision', 'value' => '#'.$revision->id],
                ['label' => 'Record', 'value' => '#'.$revision->resource_id],
                ['label' => 'Actor', 'value' => $revision->actor?->name],
                ['label' => 'Source', 'value' => $revision->source],
                ['label' => 'Reason', 'value' => $revision->reason],
            ])->filter(fn (array $pair) => filled($pair['value']))->values()->all(),
        ];
    }

    private function comparisonRevision(Revision $revision): array
    {
        return [
            'id' => $revision->id,
            'action' => $revision->action,
            'actor_name' => $revision->actor?->name,
            'created_at' => optional($revision->created_at)->toIso8601String(),
            'reason' => $revision->reason,
            'href' => route('admin.revisions.show', $revision),
        ];
    }

    private function diffPayload(array $beforePayload, array $afterPayload): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($beforePayload), array_keys($afterPayload))));
        $diff = [];

        foreach ($keys as $key) {
            $before = $beforePayload[$key] ?? null;
            $after = $afterPayload[$key] ?? null;

            if ($before !== $after) {
                $diff[$key] = [
                    'before' => $before,
                    'after' => $after,
                ];
            }
        }

        return $diff;
    }

    private function label(?string $value): string
    {
        return $value
            ? Str::of($value)->replace('_', ' ')->title()->value()
            : '—';
    }
}
