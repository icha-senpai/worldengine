<?php

namespace App\Support\WorldEngine;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\GroupRelationshipEntity;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\DocumentEntity;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\CollectionDocument;
use App\Domain\Organization\Models\CollectionEntity;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\StateRelationship;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\Temporal\Models\TimelineEntity;
use App\Domain\World\Models\GalacticRegion;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class WorldEngineUserScope
{
    private const SCOPE_NAME = 'world_engine_user';

    /** @var array<string, bool> */
    private static array $userColumnCache = [];

    /**
     * @return array<int, class-string<Model>>
     */
    public static function modelClasses(): array
    {
        return [
            Entity::class,
            EntityAlias::class,
            EntityNote::class,
            EntityQuestion::class,
            MediaReference::class,
            VersionAndCanonState::class,
            Relationship::class,
            GroupRelationship::class,
            GroupRelationshipEntity::class,
            FactionMembership::class,
            Collection::class,
            CollectionEntity::class,
            CollectionDocument::class,
            Glossary::class,
            Document::class,
            DocumentEntity::class,
            SourceCanonReference::class,
            CanonReferenceEntity::class,
            CrossoverEntryPoint::class,
            Timeline::class,
            TimelineEntity::class,
            CharacterStateTracker::class,
            StateRelationship::class,
            ConcurrencyGroup::class,
            PowerInteraction::class,
            PowerInteractionInstance::class,
            LocationContainment::class,
            TravelRoute::class,
            LocationControlHistory::class,
            GalacticRegion::class,
            KnowledgeState::class,
            Secret::class,
            PerceptionState::class,
            Meta::class,
            PipelineItem::class,
            SessionLog::class,
        ];
    }

    public static function boot(): void
    {
        foreach (self::modelClasses() as $modelClass) {
            $modelClass::addGlobalScope(self::SCOPE_NAME, function (Builder $builder): void {
                $userId = auth()->id();
                $model = $builder->getModel();

                if (! $userId || ! self::hasUserIdColumn($model)) {
                    return;
                }

                $userColumn = $model->qualifyColumn('user_id');

                $builder->where(function (Builder $query) use ($userColumn, $userId): void {
                    $query->where($userColumn, $userId);

                    if (app()->runningUnitTests()) {
                        $query->orWhereNull($userColumn);
                    }
                });
            });

            $modelClass::creating(function (Model $model): void {
                $userId = auth()->id();

                if (! $userId || ! self::hasUserIdColumn($model) || filled($model->getAttribute('user_id'))) {
                    return;
                }

                $model->setAttribute('user_id', $userId);
            });
        }
    }

    private static function hasUserIdColumn(Model $model): bool
    {
        $table = $model->getTable();

        if (self::$userColumnCache[$table] ?? false) {
            return true;
        }

        return self::$userColumnCache[$table] = Schema::hasColumn($table, 'user_id');
    }
}
