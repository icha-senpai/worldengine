<?php

namespace App\Domain\Intelligence\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Intelligence\Models\PerceptionState;

class IntelligenceService
{
    // --- KNOWLEDGE STATES ---

    public function recordKnowledge(Entity $knower, array $data): KnowledgeState
    {
        return DB::transaction(function () use ($knower, $data) {
            // Supersede any existing current knowledge state for the same knower/subject
            $existing = $this->findCurrentKnowledgeState($knower, $data);

            if ($existing) {
                $this->supersede($existing);
            }

            return KnowledgeState::create(array_merge($data, [
                'knower_entity_id' => $knower->id,
                'is_current'       => true,
                'acted_on'         => false,
            ]));
        });
    }

    public function markActedOn(KnowledgeState $state, ?array $actionNotes = null): KnowledgeState
    {
        $state->update([
            'acted_on'     => true,
            'action_notes' => $actionNotes,
        ]);

        return $state->fresh();
    }

    public function updateBeliefState(KnowledgeState $state, string $newBeliefState): KnowledgeState
    {
        if (!in_array($newBeliefState, KnowledgeState::BELIEF_STATES, true)) {
            throw new \InvalidArgumentException(
                "Invalid belief state: '{$newBeliefState}'"
            );
        }

        $state->update(['current_belief_state' => $newBeliefState]);

        return $state->fresh();
    }

    // --- SECRETS ---

    public function createSecret(array $data): Secret
    {
        return Secret::create(array_merge([
            'subject_entity_ids' => [],
            'holder_entity_ids' => [],
            'known_by_entity_ids' => [],
            'exposure_risk' => 'medium',
            'status' => 'active',
            'related_knowledge_state_ids' => [],
            'related_perception_state_ids' => [],
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
        ], $data));
    }

    public function updateSecret(Secret $secret, array $data): Secret
    {
        $secret->update($data);

        return $secret->fresh();
    }

    public function addToKnownBy(Secret $secret, int $entityId): Secret
    {
        $knownBy   = $secret->known_by_entity_ids ?? [];
        $knownBy[] = $entityId;

        $secret->update(['known_by_entity_ids' => array_unique($knownBy)]);

        // If known_by has grown beyond holders, evaluate exposure risk
        if ($secret->fresh()->isLeaking()) {
            $this->evaluateExposureRisk($secret->fresh());
        }

        return $secret->fresh();
    }

    public function addToHolders(Secret $secret, int $entityId): Secret
    {
        $holders   = $secret->holder_entity_ids ?? [];
        $holders[] = $entityId;

        // Holder is always also in known_by
        $knownBy   = $secret->known_by_entity_ids ?? [];
        $knownBy[] = $entityId;

        $secret->update([
            'holder_entity_ids'   => array_unique($holders),
            'known_by_entity_ids' => array_unique($knownBy),
        ]);

        return $secret->fresh();
    }

    public function exposeSecret(Secret $secret, string $era, string $exposureLevel = 'partially_exposed'): Secret
    {
        $secret->update([
            'status'         => $exposureLevel,
            'revealed_at_era'=> $era,
        ]);

        return $secret->fresh();
    }

    // --- PERCEPTION STATES ---

    public function createPerceptionGap(array $data): PerceptionState
    {
        // Retire any existing current perception state for this subject
        PerceptionState::forSubject($data['subject_type'], $data['subject_id'])
            ->current()
            ->update(['is_current' => false]);

        return PerceptionState::create(array_merge($data, [
            'is_current' => true,
        ]));
    }

    public function addImmuneEntity(PerceptionState $state, int $entityId): PerceptionState
    {
        $immune   = $state->immune_entity_ids ?? [];
        $immune[] = $entityId;

        $state->update(['immune_entity_ids' => array_unique($immune)]);

        // Re-evaluate revelation risk after immune list grows
        $state = $state->fresh();
        $this->evaluateRevelationRisk($state);

        return $state->fresh();
    }

    public function collapsePerceptionGap(PerceptionState $state, string $era): PerceptionState
    {
        $state->update([
            'is_current'      => false,
            'revealed_at_era' => $era,
            'revelation_risk' => 'inevitable',
        ]);

        return $state->fresh();
    }

    // --- KEY INTELLIGENCE QUERIES ---

    // The latent tension map
    // Every entity that knows something true and has not acted on it
    // Sorted by knowledge type — true_nature and secret surface first
    public function getLatentTensionMap(): Collection
    {
        return KnowledgeState::latentTension()
            ->with(['knower', 'subjectEntity', 'subjectSecret'])
            ->orderByRaw("
                CASE knowledge_type
                    WHEN 'true_nature' THEN 1
                    WHEN 'secret'      THEN 2
                    WHEN 'suspicion'   THEN 3
                    WHEN 'rumor'       THEN 4
                    ELSE 5
                END
            ")
            ->get();
    }

    // Latent tension for a specific knower
    public function getLatentTensionForEntity(Entity $entity): Collection
    {
        return KnowledgeState::latentTension()
            ->forKnower($entity->id)
            ->with(['subjectEntity', 'subjectSecret'])
            ->get();
    }

    // Exposure risk audit — active secrets ordered by structural pressure
    // Structural pressure = high risk + leaking (known_by > holders)
    public function getExposureRiskAudit(): Collection
    {
        return Secret::active()
            ->whereIn('exposure_risk', ['high', 'critical'])
            ->orderByRaw("
                CASE exposure_risk
                    WHEN 'critical' THEN 1
                    WHEN 'high'     THEN 2
                    ELSE 3
                END
            ")
            ->orderByRaw("
                jsonb_array_length(COALESCE(known_by_entity_ids, '[]'::jsonb))
                - jsonb_array_length(COALESCE(holder_entity_ids, '[]'::jsonb)) DESC
            ")
            ->get();
    }

    // Immune list tension meter
    // Perception states ordered by how much the immune list
    // has grown beyond the maintainer count
    // Highest ratio = closest to inevitable revelation
    public function getImmuneListTensionMeter(): Collection
    {
        return PerceptionState::current()
            ->where('is_current', true)
            ->orderByRaw("
                jsonb_array_length(COALESCE(immune_entity_ids, '[]'::jsonb))::float
                / NULLIF(jsonb_array_length(COALESCE(maintained_by_entity_ids, '[]'::jsonb)), 0) DESC
            ")
            ->get();
    }

    // Everything a specific entity knows, current records only
    public function getWhatEntityKnows(Entity $entity): Collection
    {
        return KnowledgeState::current()
            ->forKnower($entity->id)
            ->with(['subjectEntity', 'subjectRelationship', 'subjectSecret'])
            ->orderBy('knowledge_type')
            ->get();
    }

    // Everything known about a specific entity
    public function getWhatIsKnownAboutEntity(Entity $entity): Collection
    {
        return KnowledgeState::current()
            ->aboutEntity($entity->id)
            ->with(['knower'])
            ->orderBy('accuracy')
            ->get();
    }

    // Compartmentalizing entities — know something true, cannot face it
    // These are narrative pressure points — knowledge without action
    public function getCompartmentalizingEntities(): Collection
    {
        return KnowledgeState::current()
            ->compartmentalizing()
            ->accurate()
            ->with(['knower', 'subjectEntity'])
            ->get();
    }

    // --- PRIVATE ---

    private function findCurrentKnowledgeState(Entity $knower, array $data): ?KnowledgeState
    {
        $query = KnowledgeState::current()->forKnower($knower->id);

        // Match on whichever subject field is populated
        if (!empty($data['subject_entity_id'])) {
            $query->aboutEntity($data['subject_entity_id']);
        } elseif (!empty($data['subject_secret_id'])) {
            $query->aboutSecret($data['subject_secret_id']);
        } else {
            return null;
        }

        return $query->first();
    }

    private function supersede(KnowledgeState $state): void
    {
        $state->update([
            'is_current'       => false,
            'valid_until_era'  => $state->valid_from_era,
        ]);
    }

    // Auto-evaluate exposure risk based on structural signals
    // Called when known_by grows — does not override manually set risk
    private function evaluateExposureRisk(Secret $secret): void
    {
        $ratio = $secret->exposureRatio();

        // If more than 3x as many people know as are holding,
        // escalate to critical if not already
        if ($ratio >= 3.0 && $secret->exposure_risk !== 'critical') {
            $secret->update(['exposure_risk' => 'critical']);
        } elseif ($ratio >= 2.0 && !in_array($secret->exposure_risk, ['high', 'critical'], true)) {
            $secret->update(['exposure_risk' => 'high']);
        }
    }

    // Auto-evaluate revelation risk when immune list grows
    private function evaluateRevelationRisk(PerceptionState $state): void
    {
        $ratio = $state->immuneTensionRatio();

        if ($ratio >= 2.0 && $state->revelation_risk !== 'inevitable') {
            $state->update(['revelation_risk' => 'inevitable']);
        } elseif ($ratio >= 1.0 && !in_array($state->revelation_risk, ['critical', 'inevitable'], true)) {
            $state->update(['revelation_risk' => 'critical']);
        }
    }
}
