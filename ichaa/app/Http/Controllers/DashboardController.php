<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return $this->page('Dashboard', [
            'recentPipeline'    => $this->recentPipeline(),
            'sessionStats'      => $this->sessionStats(),
            'latentTension'     => $this->latentTension(),
            'exposureRisk'      => $this->exposureRisk(),
            'perceptionGaps'    => $this->perceptionGaps(),
            'blockingQuestions' => $this->blockingQuestions(),
        ]);
    }

    // Recent writing pipeline items — last 10 modified, not deleted
    private function recentPipeline(): array
    {
        return DB::table('writing_pipeline')
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'title', 'pipeline_type', 'pipeline_stage', 'word_count', 'updated_at'])
            ->toArray();
    }

    // Session log stats — last 30 days
    private function sessionStats(): array
    {
        $cutoff = now()->subDays(30)->toDateString();

        $rows = DB::table('session_log')
            ->whereNull('deleted_at')
            ->where('session_date', '>=', $cutoff)
            ->get(['session_significance', 'external_tool']);

        return [
            'session_count'   => $rows->count(),
            'major_count'     => $rows->where('session_significance', 'major')->count(),
            'tools_used'      => $rows->pluck('external_tool')->unique()->filter()->values()->toArray(),
        ];
    }

    // Knowledge states: is_current=true, acted_on=false, accuracy=true/confirmed
    // These are things someone knows for certain and hasn't acted on — latent tension
    private function latentTension(): array
    {
        return DB::table('knowledge_states as ks')
            ->join('entities as knower', 'knower.id', '=', 'ks.knower_entity_id')
            ->whereNull('ks.deleted_at')
            ->where('ks.is_current', true)
            ->where('ks.acted_on', false)
            ->whereIn('ks.accuracy', ['confirmed', 'true', 'accurate'])
            ->orderByDesc('ks.updated_at')
            ->limit(20)
            ->get([
                'ks.id',
                'ks.knowledge_type',
                'ks.current_belief_state',
                'ks.subject_entity_id',
                'ks.subject_secret_id',
                'knower.id as knower_id',
                'knower.name as knower_name',
            ])
            ->map(function ($row) {
                // Resolve subject name inline for entity subjects
                $subjectName = null;
                if ($row->subject_entity_id) {
                    $subjectName = DB::table('entities')
                        ->where('id', $row->subject_entity_id)
                        ->value('name');
                } elseif ($row->subject_secret_id) {
                    $subjectName = DB::table('secrets')
                        ->where('id', $row->subject_secret_id)
                        ->value('title');
                }

                return [
                    'id'                  => $row->id,
                    'knowledge_type'      => $row->knowledge_type,
                    'current_belief_state'=> $row->current_belief_state,
                    'subject_name'        => $subjectName,
                    'knower'              => ['id' => $row->knower_id, 'name' => $row->knower_name],
                ];
            })
            ->toArray();
    }

    // Secrets: active, high exposure_risk, sorted by how many people know vs hold
    // holder_entity_ids and known_by_entity_ids are jsonb arrays
    private function exposureRisk(): array
    {
        return DB::table('secrets')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereIn('exposure_risk', ['high', 'critical', 'inevitable'])
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get([
                'id',
                'title',
                'secret_type',
                'exposure_risk',
                'status',
                'holder_entity_ids',
                'known_by_entity_ids',
            ])
            ->map(function ($row) {
                $holders = json_decode($row->holder_entity_ids ?? '[]', true);
                $knownBy = json_decode($row->known_by_entity_ids ?? '[]', true);

                return [
                    'id'            => $row->id,
                    'title'         => $row->title,
                    'secret_type'   => $row->secret_type,
                    'exposure_risk' => $row->exposure_risk,
                    'holder_count'  => count($holders),
                    'known_by_count'=> count($knownBy),
                    // Leaking = more people know it than are supposed to hold it
                    'is_leaking'    => count($knownBy) > count($holders),
                ];
            })
            ->toArray();
    }

    // Perception states: is_current=true, not yet revealed
    // immune_entity_ids and maintained_by_entity_ids are jsonb arrays
    private function perceptionGaps(): array
    {
        return DB::table('perception_states')
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->whereNull('revealed_at_era')
            ->whereIn('revelation_risk', ['high', 'inevitable', 'imminent'])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get([
                'id',
                'subject_type',
                'subject_id',
                'divergence_level',
                'revelation_risk',
                'maintenance_effort',
                'immune_entity_ids',
                'maintained_by_entity_ids',
            ])
            ->map(function ($row) {
                $immune      = json_decode($row->immune_entity_ids ?? '[]', true);
                $maintainers = json_decode($row->maintained_by_entity_ids ?? '[]', true);

                return [
                    'id'               => $row->id,
                    'subject_type'     => $row->subject_type,
                    'subject_id'       => $row->subject_id,
                    'divergence_level' => $row->divergence_level,
                    'revelation_risk'  => $row->revelation_risk,
                    'maintenance_effort'=> $row->maintenance_effort,
                    'immune_count'     => count($immune),
                    'maintainer_count' => count($maintainers),
                    // High ratio = many immune relative to maintainers = fragile
                    'tension_ratio'    => count($maintainers) > 0
                        ? round(count($immune) / count($maintainers), 1)
                        : count($immune),
                ];
            })
            ->toArray();
    }

    // Entity questions that are unresolved and high/critical priority
    private function blockingQuestions(): array
    {
        return DB::table('entity_questions as eq')
            ->join('entities as e', 'e.id', '=', 'eq.entity_id')
            ->whereNull('eq.deleted_at')
            ->where('eq.status', 'unresolved')
            ->whereIn('eq.priority', ['critical', 'high'])
            ->whereNull('eq.resolved_at')
            ->orderByRaw("case eq.priority when 'critical' then 0 when 'high' then 1 else 2 end")
            ->orderByDesc('eq.updated_at')
            ->limit(15)
            ->get([
                'eq.id',
                'eq.question',
                'eq.priority',
                'e.id as entity_id',
                'e.name as entity_name',
            ])
            ->map(fn($row) => [
                'id'       => $row->id,
                'question' => $row->question,
                'priority' => $row->priority,
                'entity'   => ['id' => $row->entity_id, 'name' => $row->entity_name],
            ])
            ->toArray();
    }
}