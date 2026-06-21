<?php

namespace App\Http\Controllers\System;

use App\Domain\Connections\Models\FactionMembership;
use App\Domain\Connections\Models\GroupRelationship;
use App\Domain\Connections\Models\Relationship;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\EntityNote;
use App\Domain\Identity\Models\EntityQuestion;
use App\Domain\Intelligence\Models\KnowledgeState;
use App\Domain\Intelligence\Models\PerceptionState;
use App\Domain\Intelligence\Models\Secret;
use App\Domain\Lore\Models\CrossoverEntryPoint;
use App\Domain\Lore\Models\Document;
use App\Domain\Lore\Models\SourceCanonReference;
use App\Domain\Organization\Models\Collection;
use App\Domain\Organization\Models\Glossary;
use App\Domain\Production\Models\Meta;
use App\Domain\Production\Models\PipelineItem;
use App\Domain\Production\Models\SessionLog;
use App\Domain\Temporal\Models\CharacterStateTracker;
use App\Domain\Temporal\Models\ConcurrencyGroup;
use App\Domain\Temporal\Models\Timeline;
use App\Domain\World\Models\LocationContainment;
use App\Domain\World\Models\LocationControlHistory;
use App\Domain\World\Models\PowerInteraction;
use App\Domain\World\Models\TravelRoute;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Inertia\Response as InertiaResponse;

class TrashController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $definitions = $this->definitions();
        $selectedType = (string) $request->input('type', '');
        $query = trim((string) $request->input('q', ''));

        if ($selectedType !== '' && ! array_key_exists($selectedType, $definitions)) {
            $selectedType = '';
        }

        $visibleDefinitions = $selectedType !== ''
            ? [$selectedType => $definitions[$selectedType]]
            : $definitions;

        $items = collect($visibleDefinitions)
            ->flatMap(fn (array $definition, string $type) => $this->itemsForDefinition($type, $definition))
            ->when($query !== '', fn (SupportCollection $items) => $items->filter(
                fn (array $item) => $this->matchesSearch($item, $query)
            ))
            ->sortByDesc('deleted_at')
            ->values();

        return $this->page('System/Trash/Index', [
            'filters' => [
                'type' => $selectedType,
                'q' => $query,
            ],
            'typeOptions' => collect($definitions)
                ->map(fn (array $definition, string $type) => [
                    'value' => $type,
                    'label' => $definition['label'],
                ])
                ->values(),
            'items' => $items,
        ]);
    }

    public function restore(string $type, string $record): RedirectResponse
    {
        $definitions = $this->definitions();
        abort_unless(array_key_exists($type, $definitions), 404);

        $model = $definitions[$type]['query']()->whereKey($record)->firstOrFail();
        $model->restore();

        return $this->back('Item restored from trash.');
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     query: callable(): Builder,
     *     title: callable(mixed): string,
     *     subtitle: callable(mixed): string
     * }>
     */
    private function definitions(): array
    {
        return [
            'entities' => [
                'label' => 'Entities',
                'query' => fn (): Builder => Entity::onlyTrashed()->where('entity_type', '!=', 'timeline'),
                'title' => fn (Entity $entity): string => $entity->name,
                'subtitle' => fn (Entity $entity): string => $this->compactParts([
                    $this->formatValue($entity->entity_type),
                    $this->formatValue($entity->status),
                ]),
            ],
            'timelines' => [
                'label' => 'Timelines',
                'query' => fn (): Builder => Entity::onlyTrashed()->where('entity_type', 'timeline'),
                'title' => fn (Entity $timeline): string => $timeline->name,
                'subtitle' => fn (Entity $timeline): string => $this->compactParts([
                    'Timeline',
                    $this->formatValue($timeline->status),
                ]),
            ],
            'entity_aliases' => [
                'label' => 'Entity Aliases',
                'query' => fn (): Builder => EntityAlias::onlyTrashed()->with('entity'),
                'title' => fn (EntityAlias $alias): string => $alias->alias,
                'subtitle' => fn (EntityAlias $alias): string => $this->compactParts([
                    $alias->entity?->name ? 'For '.$alias->entity->name : '',
                    $this->formatValue($alias->alias_type),
                ]),
            ],
            'entity_notes' => [
                'label' => 'Entity Notes',
                'query' => fn (): Builder => EntityNote::onlyTrashed()->with('entity'),
                'title' => fn (EntityNote $note): string => $note->note_label ?: $this->snippet($note->content, 72),
                'subtitle' => fn (EntityNote $note): string => $this->compactParts([
                    $note->entity?->name ? 'For '.$note->entity->name : '',
                    $note->note_label ? $this->snippet($note->content, 84) : '',
                ]),
            ],
            'entity_questions' => [
                'label' => 'Entity Questions',
                'query' => fn (): Builder => EntityQuestion::onlyTrashed()->with('entity'),
                'title' => fn (EntityQuestion $question): string => $question->question,
                'subtitle' => fn (EntityQuestion $question): string => $this->compactParts([
                    $question->entity?->name ? 'For '.$question->entity->name : '',
                    $this->formatValue($question->priority),
                    $this->formatValue($question->status),
                ]),
            ],
            'relationships' => [
                'label' => 'Relationships',
                'query' => fn (): Builder => Relationship::onlyTrashed()->with(['fromEntity', 'toEntity']),
                'title' => fn (Relationship $relationship): string => $this->compactArrow(
                    $relationship->fromEntity?->name,
                    $relationship->toEntity?->name,
                    'Relationship #'.$relationship->id
                ),
                'subtitle' => fn (Relationship $relationship): string => $this->formatValue($relationship->relationship_type),
            ],
            'group_relationships' => [
                'label' => 'Group Relationships',
                'query' => fn (): Builder => GroupRelationship::onlyTrashed(),
                'title' => fn (GroupRelationship $group): string => $group->name ?: 'Group Relationship #'.$group->id,
                'subtitle' => fn (GroupRelationship $group): string => $this->formatValue($group->relationship_type),
            ],
            'faction_memberships' => [
                'label' => 'Faction Memberships',
                'query' => fn (): Builder => FactionMembership::onlyTrashed()->with(['member', 'faction']),
                'title' => fn (FactionMembership $membership): string => $this->compactArrow(
                    $membership->member?->name,
                    $membership->faction?->name,
                    'Faction Membership #'.$membership->id,
                    ' in '
                ),
                'subtitle' => fn (FactionMembership $membership): string => $this->compactParts([
                    $this->formatValue($membership->rank_or_role),
                    $this->formatValue($membership->membership_status),
                ]),
            ],
            'collections' => [
                'label' => 'Collections',
                'query' => fn (): Builder => Collection::onlyTrashed(),
                'title' => fn (Collection $collection): string => $collection->name,
                'subtitle' => fn (Collection $collection): string => $this->compactParts([
                    $this->formatValue($collection->collection_type),
                    $this->formatValue($collection->collection_mode),
                ]),
            ],
            'glossary' => [
                'label' => 'Glossary Terms',
                'query' => fn (): Builder => Glossary::onlyTrashed(),
                'title' => fn (Glossary $glossary): string => $glossary->term,
                'subtitle' => fn (Glossary $glossary): string => $this->snippet($glossary->usage_context, 84),
            ],
            'documents' => [
                'label' => 'Documents',
                'query' => fn (): Builder => Document::onlyTrashed(),
                'title' => fn (Document $document): string => $document->title,
                'subtitle' => fn (Document $document): string => $this->compactParts([
                    $this->formatValue($document->document_type),
                    $this->formatValue($document->document_status),
                ]),
            ],
            'canon_references' => [
                'label' => 'Canon References',
                'query' => fn (): Builder => SourceCanonReference::onlyTrashed(),
                'title' => fn (SourceCanonReference $reference): string => $reference->title,
                'subtitle' => fn (SourceCanonReference $reference): string => $this->compactParts([
                    $reference->universe,
                    $this->formatValue($reference->level),
                ]),
            ],
            'crossover_entry_points' => [
                'label' => 'Crossover Entry Points',
                'query' => fn (): Builder => CrossoverEntryPoint::onlyTrashed(),
                'title' => fn (CrossoverEntryPoint $entryPoint): string => $entryPoint->source_universe ?: 'Crossover Entry #'.$entryPoint->id,
                'subtitle' => fn (CrossoverEntryPoint $entryPoint): string => $this->compactParts([
                    $this->formatValue($entryPoint->status),
                    $entryPoint->target_location,
                ]),
            ],
            'timeline_entries' => [
                'label' => 'Timeline Entries',
                'query' => fn (): Builder => Timeline::onlyTrashed()->with(['timeline', 'eventEntity', 'concurrencyGroup']),
                'title' => fn (Timeline $entry): string => $entry->entry_label ?: $entry->eventEntity?->name ?: 'Timeline Entry #'.$entry->id,
                'subtitle' => fn (Timeline $entry): string => $this->compactParts([
                    $entry->timeline?->name ? 'On '.$entry->timeline->name : '',
                    $entry->au_date,
                    $entry->concurrencyGroup?->name,
                ]),
            ],
            'character_states' => [
                'label' => 'Character States',
                'query' => fn (): Builder => CharacterStateTracker::onlyTrashed()->with('entity'),
                'title' => fn (CharacterStateTracker $state): string => $state->snapshot_label ?: $state->entity?->name ?: 'Character State #'.$state->id,
                'subtitle' => fn (CharacterStateTracker $state): string => $this->compactParts([
                    $state->entity?->name,
                    $this->formatValue($state->current_stability_level),
                ]),
            ],
            'concurrency_groups' => [
                'label' => 'Concurrency Groups',
                'query' => fn (): Builder => ConcurrencyGroup::onlyTrashed(),
                'title' => fn (ConcurrencyGroup $group): string => $group->name,
                'subtitle' => fn (ConcurrencyGroup $group): string => $this->compactParts([
                    $group->au_date,
                    $this->formatValue($group->narrative_significance),
                ]),
            ],
            'power_interactions' => [
                'label' => 'Power Interactions',
                'query' => fn (): Builder => PowerInteraction::onlyTrashed(),
                'title' => fn (PowerInteraction $interaction): string => $interaction->interaction_name ?: 'Power Interaction #'.$interaction->id,
                'subtitle' => fn (PowerInteraction $interaction): string => $this->compactParts([
                    $this->formatValue($interaction->interaction_type),
                    $this->formatValue($interaction->knowledge_state),
                ]),
            ],
            'location_containment' => [
                'label' => 'Location Containment',
                'query' => fn (): Builder => LocationContainment::onlyTrashed()->with(['childLocation', 'parentLocation']),
                'title' => fn (LocationContainment $containment): string => $this->compactArrow(
                    $containment->childLocation?->name,
                    $containment->parentLocation?->name,
                    'Containment #'.$containment->id,
                    ' in '
                ),
                'subtitle' => fn (LocationContainment $containment): string => $this->formatValue($containment->containment_type),
            ],
            'travel_routes' => [
                'label' => 'Travel Routes',
                'query' => fn (): Builder => TravelRoute::onlyTrashed()->with(['origin', 'destination']),
                'title' => fn (TravelRoute $route): string => $this->compactArrow(
                    $route->origin?->name,
                    $route->destination?->name,
                    'Travel Route #'.$route->id
                ),
                'subtitle' => fn (TravelRoute $route): string => $this->formatValue($route->route_type),
            ],
            'location_control' => [
                'label' => 'Location Control',
                'query' => fn (): Builder => LocationControlHistory::onlyTrashed()->with(['location', 'controllingEntity']),
                'title' => fn (LocationControlHistory $record): string => $this->compactArrow(
                    $record->location?->name,
                    $record->controllingEntity?->name,
                    'Location Control #'.$record->id,
                    ' controlled by '
                ),
                'subtitle' => fn (LocationControlHistory $record): string => $this->compactParts([
                    $this->formatValue($record->control_type),
                    $this->formatValue($record->resistance_level),
                ]),
            ],
            'knowledge_states' => [
                'label' => 'Knowledge States',
                'query' => fn (): Builder => KnowledgeState::onlyTrashed()->with(['knower', 'subjectEntity']),
                'title' => fn (KnowledgeState $state): string => $state->knower?->name
                    ? $state->knower->name.' Knowledge'
                    : 'Knowledge State #'.$state->id,
                'subtitle' => fn (KnowledgeState $state): string => $this->compactParts([
                    $state->subjectEntity?->name ? 'About '.$state->subjectEntity->name : '',
                    $this->formatValue($state->knowledge_type),
                ]),
            ],
            'secrets' => [
                'label' => 'Secrets',
                'query' => fn (): Builder => Secret::onlyTrashed(),
                'title' => fn (Secret $secret): string => $secret->title,
                'subtitle' => fn (Secret $secret): string => $this->compactParts([
                    $this->formatValue($secret->secret_type),
                    $this->formatValue($secret->exposure_risk),
                ]),
            ],
            'perception_states' => [
                'label' => 'Perception States',
                'query' => fn (): Builder => PerceptionState::onlyTrashed(),
                'title' => fn (PerceptionState $state): string => $this->formatValue($state->subject_type).' Perception Gap',
                'subtitle' => fn (PerceptionState $state): string => $this->compactParts([
                    $state->subject_id ? 'Subject #'.$state->subject_id : '',
                    $this->formatValue($state->divergence_level),
                ]),
            ],
            'meta' => [
                'label' => 'Meta Notes',
                'query' => fn (): Builder => Meta::onlyTrashed(),
                'title' => fn (Meta $note): string => $note->title,
                'subtitle' => fn (Meta $note): string => $this->compactParts([
                    $this->formatValue($note->category),
                    $this->formatValue($note->action_status),
                ]),
            ],
            'pipeline' => [
                'label' => 'Pipeline Items',
                'query' => fn (): Builder => PipelineItem::onlyTrashed(),
                'title' => fn (PipelineItem $item): string => $item->title,
                'subtitle' => fn (PipelineItem $item): string => $this->compactParts([
                    $this->formatValue($item->pipeline_type),
                    $this->formatValue($item->pipeline_stage),
                ]),
            ],
            'session_logs' => [
                'label' => 'Session Logs',
                'query' => fn (): Builder => SessionLog::onlyTrashed(),
                'title' => fn (SessionLog $session): string => $session->title,
                'subtitle' => fn (SessionLog $session): string => $this->compactParts([
                    $session->session_date,
                    $session->external_tool,
                ]),
            ],
        ];
    }

    /**
     * @param  array{
     *     label: string,
     *     query: callable(): Builder,
     *     title: callable(mixed): string,
     *     subtitle: callable(mixed): string
     * } $definition
     */
    private function itemsForDefinition(string $type, array $definition): SupportCollection
    {
        return $definition['query']()
            ->get()
            ->map(fn ($record) => [
                'id' => $record->getKey(),
                'type' => $type,
                'resource_label' => $definition['label'],
                'title' => trim((string) $definition['title']($record)),
                'subtitle' => trim((string) $definition['subtitle']($record)),
                'deleted_at' => $record->deleted_at?->toIso8601String(),
            ]);
    }

    private function matchesSearch(array $item, string $query): bool
    {
        $haystack = Str::lower(implode(' ', [
            $item['resource_label'] ?? '',
            $item['title'] ?? '',
            $item['subtitle'] ?? '',
        ]));

        return Str::contains($haystack, Str::lower($query));
    }

    private function formatValue(mixed $value): string
    {
        $string = trim((string) $value);

        if ($string === '') {
            return '';
        }

        return Str::of($string)->replace('_', ' ')->title()->toString();
    }

    private function compactParts(array $parts): string
    {
        return implode(' · ', array_values(array_filter($parts, fn ($part) => filled($part))));
    }

    private function compactArrow(?string $left, ?string $right, string $fallback, string $separator = ' -> '): string
    {
        if ($left && $right) {
            return $left.$separator.$right;
        }

        return $fallback;
    }

    private function snippet(mixed $value, int $limit = 90): string
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }

        return Str::limit(trim((string) $value), $limit);
    }
}
