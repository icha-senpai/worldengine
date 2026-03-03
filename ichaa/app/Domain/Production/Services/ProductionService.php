<?php

namespace App\Domain\Production\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

use App\Domain\Identity\Models\Entity;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;

class ProductionService
{
    // --- META ---

    public function createMeta(array $data): Meta
    {
        return Meta::create($data);
    }

    public function updateMeta(Meta $meta, array $data): Meta
    {
        $meta->update($data);

        return $meta->fresh();
    }

    public function advanceStatus(Meta $meta): Meta
    {
        $progression = [
            'planned'    => 'outlining',
            'outlining'  => 'drafting',
            'drafting'   => 'revising',
            'revising'   => 'complete',
        ];

        $next = $progression[$meta->status] ?? null;

        if (!$next) {
            return $meta;
        }

        $updates = ['status' => $next];

        if ($next === 'complete') {
            $updates['completed_at'] = now();
        }

        if ($next === 'outlining' && !$meta->started_at) {
            $updates['started_at'] = now();
        }

        $meta->update($updates);

        return $meta->fresh();
    }

    public function linkEntity(Meta $meta, Entity $entity, array $pivotData = []): void
    {
        $meta->entities()->syncWithoutDetaching([
            $entity->id => $pivotData,
        ]);
    }

    public function unlinkEntity(Meta $meta, Entity $entity): void
    {
        $meta->entities()->detach($entity->id);
    }

    // --- PIPELINE ---

    public function createPipelineItem(Meta $meta, array $data): PipelineItem
    {
        $maxOrder = PipelineItem::forMeta($meta->id)->max('sort_order') ?? 0;

        return PipelineItem::create(array_merge($data, [
            'meta_id'    => $meta->id,
            'status'     => $data['status'] ?? 'backlog',
            'sort_order' => $maxOrder + 1,
        ]));
    }

    public function createEntityPipelineItem(
        Entity $entity,
        array $data,
        ?Meta $meta = null
    ): PipelineItem {
        $maxOrder = PipelineItem::forEntity($entity->id)->max('sort_order') ?? 0;

        return PipelineItem::create(array_merge($data, [
            'entity_id'  => $entity->id,
            'meta_id'    => $meta?->id,
            'status'     => $data['status'] ?? 'backlog',
            'sort_order' => $maxOrder + 1,
        ]));
    }

    public function updatePipelineItem(PipelineItem $item, array $data): PipelineItem
    {
        $item->update($data);

        return $item->fresh();
    }

    public function resolvePipelineItem(PipelineItem $item, ?array $resolutionNotes = null): PipelineItem
    {
        $item->resolve($resolutionNotes ?? []);

        return $item->fresh();
    }

    public function reorderPipelineItems(Meta $meta, array $orderedIds): void
    {
        // $orderedIds is an array of pipeline item IDs in the desired order
        foreach ($orderedIds as $position => $id) {
            PipelineItem::where('id', $id)
                ->where('meta_id', $meta->id)
                ->update(['sort_order' => $position + 1]);
        }
    }

    // --- SESSION LOGS ---

    public function startSession(array $data): SessionLog
    {
        return SessionLog::create(array_merge($data, [
            'session_date' => $data['session_date'] ?? now()->toDateString(),
        ]));
    }

    public function updateSession(SessionLog $session, array $data): SessionLog
    {
        $session->update($data);

        return $session->fresh();
    }

    public function logEntityCreated(SessionLog $session, int $entityId): void
    {
        $session->addCreatedEntity($entityId);
    }

    public function logEntityModified(SessionLog $session, int $entityId): void
    {
        $session->addModifiedEntity($entityId);
    }

    // --- QUERIES ---

    // Dashboard writing summary — active metas with pending pipeline items
    public function getActiveSummary(): Collection
    {
        return Meta::active()
            ->with(['pendingItems' => function ($query) {
                $query->forDashboard();
            }])
            ->ordered()
            ->get();
    }

    // All high/critical pipeline items across all metas
    public function getCriticalPipeline(): Collection
    {
        return PipelineItem::forDashboard()->with(['meta', 'entity'])->get();
    }

    // Session stats for the last N days
    public function getSessionStats(int $days = 30): array
    {
        $sessions = SessionLog::recent($days)->get();

        return [
            'session_count'       => $sessions->count(),
            'total_words'         => $sessions->sum('word_count_added'),
            'total_minutes'       => $sessions->sum('duration_minutes'),
            'entities_created'    => $sessions->sum(fn($s) => $s->entityCreatedCount()),
            'entities_modified'   => $sessions->sum(fn($s) => $s->entityModifiedCount()),
            'average_mood'        => round($sessions->avg('mood_rating'), 1),
        ];
    }

    // Recent session history, latest first
    public function getRecentSessions(int $limit = 10): Collection
    {
        return SessionLog::latestFirst()->limit($limit)->get();
    }
}
