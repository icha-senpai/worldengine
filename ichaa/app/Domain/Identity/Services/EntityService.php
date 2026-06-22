<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\VersionAndCanonState;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\Listeners\UpdateCompletionScore;
use App\Domain\Identity\Listeners\FlipEntityCompletionFlags;

use App\Domain\Identity\Exceptions\InvalidEntityTypeException;
use App\Domain\Identity\Exceptions\CannotPublishIncompleteEntityException;

class EntityService
{
    public function __construct(
        private readonly UpdateCompletionScore      $completionScoreUpdater,
        private readonly FlipEntityCompletionFlags  $flagFlipper,
        private readonly CompletionScoreCalculator  $calculator,
    ) {}

    // --- CREATE ---

    public function create(array $data): Entity
    {
        // Validate entity type before anything touches the database
        try {
            EntityType::from($data['entity_type']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidEntityTypeException($data['entity_type']);
        }

        return DB::transaction(function () use ($data) {
            // Apply defaults from settings if not provided
            $data['visibility']              ??= VisibilityLevel::PRIVATE;
            $data['content_classification']  ??= ContentClassification::RESTRICTED;
            $data['status']                  ??= 'concept';

            $entity = Entity::create($data);

            // Create the initial version zero shell
            // Empty snapshot — entity was just created
            // User fills it in as they build the entity out
            $this->createInitialVersion($entity);

            // Calculate initial completion score
            $this->completionScoreUpdater->recalculate($entity);

            return $entity->fresh();
        });
    }

    // --- UPDATE ---

    public function update(Entity $entity, array $data): Entity
    {
        return DB::transaction(function () use ($entity, $data) {
            $entity->update($data);

            // Recalculate completion score after any update
            // The booted() hooks fire domain events for significant field changes
            // Canon state snapshots are handled by the listener, not here
            $this->completionScoreUpdater->recalculate($entity);

            return $entity->fresh();
        });
    }

    // --- PUBLISH ---

    public function publish(Entity $entity): Entity
    {
        if ($entity->completion_score < 50) {
            throw new CannotPublishIncompleteEntityException(
                $entity,
                "Completion score is {$entity->completion_score}/100. Minimum 50 required to publish."
            );
        }

        return $this->update($entity, [
            'visibility'   => VisibilityLevel::PUBLIC_KNOWLEDGE,
            'published_at' => Carbon::now(),
        ]);
    }

    public function unpublish(Entity $entity): Entity
    {
        return $this->update($entity, [
            'visibility'   => VisibilityLevel::PRIVATE,
            'published_at' => null,
        ]);
    }

    // --- ARCHIVE ---

    public function archive(Entity $entity): Entity
    {
        return $this->update($entity, ['status' => 'archived']);
    }

    // --- DELETE ---

    // Soft delete — entity remains in database, just hidden
    public function delete(Entity $entity): void
    {
        $entity->delete();
    }

    // Hard delete — permanent, use with extreme caution
    // Only called explicitly, never from UI without confirmation
    public function forceDelete(Entity $entity): void
    {
        DB::transaction(function () use ($entity) {
            // Remove all related records first to avoid FK violations
            $entity->aliases()->forceDelete();
            $entity->notes()->forceDelete();
            $entity->questions()->forceDelete();
            $entity->media()->forceDelete();
            $entity->versions()->forceDelete();

            $entity->forceDelete();
        });
    }

    // --- CANON STATE ---

    // Manual canon state snapshot — user explicitly saved this version
    public function saveManualCanonState(Entity $entity, array $data = []): VersionAndCanonState
    {
        return DB::transaction(function () use ($entity, $data) {
            // Mark existing current version as no longer current
            VersionAndCanonState::where('entity_id', $entity->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $nextVersion = VersionAndCanonState::where('entity_id', $entity->id)
                ->max('version_number') + 1;

            return VersionAndCanonState::create([
                'entity_id'       => $entity->id,
                'version_type'    => 'soft',
                'version_number'  => $nextVersion,
                'version_label'   => $data['version_label'] ?? null,
                'version_state'   => 'current',
                'is_current'      => true,
                'is_version_zero' => false,
                'entity_snapshot' => $entity->toArray(),
                'what_changed'    => $data['what_changed'] ?? null,
                'why_changed'     => $data['why_changed'] ?? null,
                'trigger_type'    => 'manual',
                'valid_from_era'  => $data['valid_from_era'] ?? null,
                'visibility'      => $entity->visibility,
                'content_classification' => $entity->content_classification,
            ]);
        });
    }

    // Save a version zero — source canon capture
    // is_version_zero: true, separate from the soft version chain
    public function saveVersionZero(Entity $entity, array $data = []): VersionAndCanonState
    {
        // Version zero does not displace the current soft version chain
        // It is a parallel record representing source canon state
        return VersionAndCanonState::create([
            'entity_id'                => $entity->id,
            'version_type'             => 'soft',
            'version_number'           => 0,
            'version_label'            => $data['version_label'] ?? "Version Zero — {$entity->name}",
            'version_state'            => 'current',
            'is_current'               => false, // Version zero is never the active version
            'is_version_zero'          => true,
            'version_zero_confidence'  => $data['version_zero_confidence'] ?? 'rough',
            'version_zero_notes'       => $data['version_zero_notes'] ?? null,
            'entity_snapshot'          => $entity->toArray(),
            'trigger_type'             => 'manual',
            'visibility'               => VisibilityLevel::PRIVATE,
            'content_classification'   => ContentClassification::AUTHOR_ONLY,
        ]);
    }

    // Register a hard iteration — Harry v1 through v69
    public function registerIteration(
        Entity $sourceEntity,
        Entity $iterationEntity,
        array  $data = []
    ): VersionAndCanonState {
        $iterationNumber = VersionAndCanonState::where('source_entity_id', $sourceEntity->id)
            ->max('iteration_number') + 1;

        return VersionAndCanonState::create([
            'entity_id'          => $iterationEntity->id,
            'version_type'       => 'hard_iteration',
            'version_number'     => 1,
            'version_label'      => $data['version_label'] ?? "{$sourceEntity->name} v{$iterationNumber}",
            'version_state'      => 'current',
            'is_current'         => true,
            'is_version_zero'    => false,
            'entity_snapshot'    => $iterationEntity->toArray(),
            'trigger_type'       => 'manual',
            'iteration_number'   => $iterationNumber,
            'source_entity_id'   => $sourceEntity->id,
            'retained_from_previous' => $data['retained_from_previous'] ?? null,
            'valid_from_era'     => $data['valid_from_era'] ?? null,
            'visibility'         => $iterationEntity->visibility,
            'content_classification' => $iterationEntity->content_classification,
        ]);
    }

    // Terminate a hard iteration — marks it as iteration_failed
    public function terminateIteration(
        VersionAndCanonState $iteration,
        Entity $terminatedBy,
        array  $data = []
    ): VersionAndCanonState {
        $iteration->update([
            'version_state'          => 'iteration_failed',
            'is_current'             => false,
            'what_failed'            => $data['what_failed'] ?? null,
            'failure_era'            => $data['failure_era'] ?? null,
            'terminated_by_entity_id' => $terminatedBy->id,
            'valid_until_era'        => $data['failure_era'] ?? null,
        ]);

        return $iteration->fresh();
    }

    // --- PRIVATE ---

    private function createInitialVersion(Entity $entity): VersionAndCanonState
    {
        return VersionAndCanonState::create([
            'entity_id'       => $entity->id,
            'version_type'    => 'soft',
            'version_number'  => 1,
            'version_label'   => "Initial — {$entity->name}",
            'version_state'   => 'current',
            'is_current'      => true,
            'is_version_zero' => false,
            'entity_snapshot' => $entity->toArray(),
            'trigger_type'    => 'manual',
            'visibility'      => $entity->visibility,
            'content_classification' => $entity->content_classification,
        ]);
    }
}
