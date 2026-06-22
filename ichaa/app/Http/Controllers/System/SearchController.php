<?php

namespace App\Http\Controllers\System;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\Document;
use App\Domain\Organization\Models\Glossary;
use App\Domain\System\Models\NotionNote;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Response;

class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $term = $request->string('q')->trim()->toString();

        if (empty($term)) {
            return $this->page('Search/Index', [
                'results' => [],
                'term' => '',
            ]);
        }

        $entityNoteMatches = $this->matchingNotionNoteExcerpts(Entity::class, 'entities', $term, 10);
        $documentNoteMatches = $this->matchingNotionNoteExcerpts(Document::class, 'documents', $term, 5);
        $secretNoteMatches = $this->matchingNotionNoteExcerpts(Secret::class, 'secrets', $term, 5);
        $glossaryNoteMatches = $this->matchingNotionNoteExcerpts(Glossary::class, 'glossary', $term, 5);

        $entityResults = Entity::search($term)
            ->select(['id', 'name', 'entity_type', 'status', 'summary'])
            ->take(10)
            ->get();
        $entityResults = $this->appendNotionNoteMatches(
            $entityResults,
            Entity::query()->select(['id', 'name', 'entity_type', 'status', 'summary'])->orderBy('name'),
            $entityNoteMatches,
            10,
        );

        $documentResults = Document::search($term)
            ->select(['id', 'title', 'document_type', 'document_status'])
            ->take(5)
            ->get();
        $documentResults = $this->appendNotionNoteMatches(
            $documentResults,
            Document::query()->select(['id', 'title', 'document_type', 'document_status'])->orderBy('title'),
            $documentNoteMatches,
            5,
        );

        $secretResults = Secret::search($term)
            ->select(['id', 'title', 'secret_type', 'exposure_risk'])
            ->take(5)
            ->get();
        $secretResults = $this->appendNotionNoteMatches(
            $secretResults,
            Secret::query()->select(['id', 'title', 'secret_type', 'exposure_risk'])->orderBy('title'),
            $secretNoteMatches,
            5,
        );

        $glossaryResults = Glossary::search($term)
            ->select(['id', 'term', 'usage_context'])
            ->take(5)
            ->get();
        $glossaryResults = $this->appendNotionNoteMatches(
            $glossaryResults,
            Glossary::query()->select(['id', 'term', 'usage_context'])->orderBy('term'),
            $glossaryNoteMatches,
            5,
        );

        $results = [
            'entities' => $entityResults,
            'documents' => $documentResults,
            'secrets' => $secretResults,
            'glossary' => $glossaryResults,
        ];

        return $this->page('Search/Index', [
            'results' => $results,
            'term' => $term,
        ]);
    }

    private function appendNotionNoteMatches(Collection $results, Builder $fallbackQuery, Collection $noteMatches, int $limit): Collection
    {
        $remaining = $limit - $results->count();

        if ($remaining > 0) {
            $existingIds = $results->pluck('id')->map(fn ($id) => (int) $id);
            $missingIds = $noteMatches->keys()
                ->map(fn ($id) => (int) $id)
                ->diff($existingIds)
                ->take($remaining)
                ->values();

            if ($missingIds->isNotEmpty()) {
                $missingIdOrder = $missingIds->values();

                $extraResults = (clone $fallbackQuery)
                    ->whereIn('id', $missingIds->all())
                    ->get()
                    ->sortBy(fn ($model) => $missingIdOrder->search((int) $model->getKey()))
                    ->values();

                $results = $results->concat($extraResults);
            }
        }

        return $results
            ->map(function ($model) use ($noteMatches) {
                $model->setAttribute('notion_note_excerpt', $noteMatches->get((int) $model->getKey()));

                return $model;
            })
            ->values();
    }

    private function matchingNotionNoteExcerpts(string $modelClass, string $resource, string $term, int $limit): Collection
    {
        $like = '%' . $this->escapeLike($term) . '%';

        return NotionNote::query()
            ->where('noteable_type', $modelClass)
            ->where('sync_resource', $resource)
            ->whereRaw("content ILIKE ? ESCAPE '\\'", [$like])
            ->orderByDesc('last_synced_at')
            ->limit($limit)
            ->get(['noteable_id', 'content'])
            ->keyBy(fn (NotionNote $note) => (int) $note->noteable_id)
            ->map(fn (NotionNote $note) => $this->excerptAroundMatch((string) $note->content, $term));
    }

    private function excerptAroundMatch(string $content, string $term, int $radius = 80): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');

        if ($normalized === '') {
            return '';
        }

        $position = mb_stripos($normalized, $term);

        if ($position === false) {
            return Str::limit($normalized, $radius * 2);
        }

        $start = max(0, $position - $radius);
        $length = min(mb_strlen($normalized) - $start, mb_strlen($term) + ($radius * 2));
        $excerpt = mb_substr($normalized, $start, $length);

        if ($start > 0) {
            $excerpt = '…' . $excerpt;
        }

        if (($start + $length) < mb_strlen($normalized)) {
            $excerpt .= '…';
        }

        return $excerpt;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
