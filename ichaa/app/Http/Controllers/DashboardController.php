<?php

namespace App\Http\Controllers;

use Inertia\Response;

use App\Domain\Identity\Models\Entity;
use App\Domain\Intelligence\Services\IntelligenceService;
use App\Domain\Production\Services\ProductionService;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\Identity\Models\EntityQuestion;

class DashboardController extends Controller
{
    public function __construct(
        private readonly IntelligenceService $intelligence,
        private readonly ProductionService   $production,
    ) {}

    public function index(): Response
    {
        return $this->page('Dashboard', [

            // --- WRITING SUMMARY ---
            // Active meta projects with their pending pipeline items
            'activeMeta' => $this->production->getActiveSummary()
                ->map(fn($meta) => [
                    'id'               => $meta->id,
                    'title'            => $meta->title,
                    'meta_type'        => $meta->meta_type,
                    'status'           => $meta->status,
                    'word_count'       => $meta->current_word_count,
                    'target_words'     => $meta->target_word_count,
                    'progress_percent' => $meta->wordCountProgress(),
                    'pending_items'    => $meta->pendingItems->map(fn($item) => [
                        'id'       => $item->id,
                        'title'    => $item->title,
                        'type'     => $item->item_type,
                        'priority' => $item->priority,
                        'status'   => $item->status,
                    ]),
                ]),

            // Recent session stats
            'sessionStats' => $this->production->getSessionStats(30),

            // --- WARNING PANELS ---

            // Latent tension — knows something true, hasn't acted
            'latentTension' => $this->intelligence->getLatentTensionMap()
                ->take(20)
                ->map(fn($state) => [
                    'id'             => $state->id,
                    'knower'         => [
                        'id'   => $state->knower?->id,
                        'name' => $state->knower?->name,
                    ],
                    'knowledge_type' => $state->knowledge_type,
                    'subject_type'   => $state->subjectType(),
                    'subject_name'   => $this->resolveSubjectName($state),
                    'accuracy'       => $state->accuracy,
                    'belief_state'   => $state->current_belief_state,
                ]),

            // Exposure risk — high/critical secrets sorted by structural pressure
            'exposureRisk' => $this->intelligence->getExposureRiskAudit()
                ->take(15)
                ->map(fn($secret) => [
                    'id'             => $secret->id,
                    'title'          => $secret->title,
                    'secret_type'    => $secret->secret_type,
                    'exposure_risk'  => $secret->exposure_risk,
                    'status'         => $secret->status,
                    'holder_count'   => $secret->holderCount(),
                    'known_by_count' => $secret->knownByCount(),
                    'exposure_ratio' => $secret->exposureRatio(),
                    'is_leaking'     => $secret->isLeaking(),
                ]),

            // Immune list tension — perception gaps closest to collapse
            'immuneTension' => $this->intelligence->getImmuneListTensionMeter()
                ->take(10)
                ->map(fn($state) => [
                    'id'               => $state->id,
                    'subject_type'     => $state->subject_type,
                    'subject_id'       => $state->subject_id,
                    'divergence_level' => $state->divergence_level,
                    'revelation_risk'  => $state->revelation_risk,
                    'maintenance_effort'=> $state->maintenance_effort,
                    'immune_count'     => $state->immuneCount(),
                    'maintainer_count' => $state->maintainerCount(),
                    'tension_ratio'    => $state->immuneTensionRatio(),
                ]),

            // Blocking questions — entity questions flagged as blocking
            'blockingQuestions' => EntityQuestion::blocking()
                ->unresolved()
                ->byPriority()
                ->with('entity:id,name,entity_type')
                ->take(15)
                ->get()
                ->map(fn($q) => [
                    'id'       => $q->id,
                    'question' => $q->question,
                    'priority' => $q->priority,
                    'entity'   => [
                        'id'          => $q->entity->id,
                        'name'        => $q->entity->name,
                        'entity_type' => $q->entity->entity_type,
                    ],
                ]),

            // Unresolved power interactions
            'unresolvedInteractions' => PowerInteraction::unresolved()
                ->with(['systemA:id,name', 'systemB:id,name'])
                ->take(10)
                ->get()
                ->map(fn($i) => [
                    'id'            => $i->id,
                    'name'          => $i->interaction_name,
                    'system_a'      => $i->systemA?->name,
                    'system_b'      => $i->systemB?->name,
                    'danger_rating' => $i->danger_rating,
                    'knowledge_state'=> $i->knowledge_state,
                ]),

            // Incomplete entities
            'incompleteEntities' => Entity::incomplete()
                ->withBlockingQuestions()
                ->select(['id', 'name', 'entity_type', 'completion_score', 'status'])
                ->orderByDesc('completion_score') // Closest to complete first
                ->take(10)
                ->get()
                ->map(fn($e) => [
                    'id'               => $e->id,
                    'name'             => $e->name,
                    'entity_type'      => $e->entity_type,
                    'completion_score' => $e->completion_score,
                    'status'           => $e->status,
                ]),

        ]);
    }

    // Resolve a human-readable name for a knowledge state's subject
    // regardless of which subject type it is
    private function resolveSubjectName($state): ?string
    {
        return match($state->subjectType()) {
            'entity'             => $state->subjectEntity?->name,
            'secret'             => $state->subjectSecret?->title,
            'relationship'       => $state->subjectRelationship
                ? ($state->subjectRelationship->fromEntity?->name . ' → ' . $state->subjectRelationship->toEntity?->name)
                : null,
            'group_relationship' => $state->subjectGroupRelationship?->name,
            'event'              => $state->subjectEvent?->eventEntity?->name,
            default              => null,
        };
    }
}
