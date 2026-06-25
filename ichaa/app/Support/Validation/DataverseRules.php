<?php

namespace App\Support\Validation;

use App\Domain\Connections\ValueObjects\RelationshipType;
use App\Domain\Connections\ValueObjects\TensionCharge;
use App\Domain\Identity\Models\EntityAlias;
use App\Domain\Identity\Models\MediaReference;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use App\Domain\Identity\ValueObjects\EntityType;
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
use App\Domain\World\Models\PowerInteractionInstance;
use App\Domain\World\Models\TravelRoute;
use App\Support\Api\ApiResourceRegistry;
use Closure;
use Illuminate\Database\Eloquent\Model;

class DataverseRules
{
    public static function index(): array
    {
        return [
            'include' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with_trashed' => ['nullable', 'boolean'],
            'only_trashed' => ['nullable', 'boolean'],
            'filter' => ['nullable', 'array'],
        ];
    }

    public static function web(string $resource, string $operation): array
    {
        return self::flatten(self::definition($resource, $operation));
    }

    public static function api(string $resource, string $operation): array
    {
        return self::prefix(self::definition($resource, $operation));
    }

    public static function apiAction(string $action): array
    {
        return self::prefix(self::actionDefinition($action));
    }

    public static function apiMediaUpload(): array
    {
        $rules = array_merge(
            self::api('media-references', 'store'),
            self::metaRules(false),
            [
                'data.file' => ['required', 'array'],
                'data.file.name' => ['required', 'string', 'max:255'],
                'data.file.mime_type' => ['nullable', 'string', 'max:255'],
                'data.file.content_base64' => ['required', 'string'],
            ],
        );

        $uploadableMediaTypes = array_values(array_filter(
            MediaReference::MEDIA_TYPES,
            static fn (string $type) => $type !== 'link',
        ));

        $rules['data.attributes.media_type'] = ['required', 'string', 'in:'.implode(',', $uploadableMediaTypes)];
        $rules['data.attributes.file_path'] = ['prohibited'];
        $rules['data.attributes.url'] = ['prohibited'];
        $rules['data.attributes.file_name'] = ['prohibited'];
        $rules['data.attributes.file_extension'] = ['prohibited'];
        $rules['data.attributes.file_size_bytes'] = ['prohibited'];
        $rules['data.attributes.mime_type'] = ['prohibited'];
        $rules['data.attributes.width_px'] = ['prohibited'];
        $rules['data.attributes.height_px'] = ['prohibited'];

        return $rules;
    }

    public static function apiMediaReplace(): array
    {
        return array_merge(
            self::metaRules(true),
            [
                'data' => ['required', 'array'],
                'data.file' => ['required', 'array'],
                'data.file.name' => ['required', 'string', 'max:255'],
                'data.file.mime_type' => ['nullable', 'string', 'max:255'],
                'data.file.content_base64' => ['required', 'string'],
            ],
        );
    }

    public static function webAction(string $action): array
    {
        return self::flatten(self::actionDefinition($action));
    }

    public static function metaRules(bool $requireBaseRevision = false): array
    {
        return [
            'meta' => ['nullable', 'array'],
            'meta.base_revision_id' => $requireBaseRevision
                ? ['required', 'integer', 'min:0']
                : ['nullable', 'integer', 'min:0'],
            'meta.reason' => ['nullable', 'string'],
            'meta.source' => ['nullable', 'string', 'max:255'],
            'meta.validate_only' => ['nullable', 'boolean'],
        ];
    }

    private static function richDocumentRule(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && ! is_array($value) && ! is_string($value)) {
                    $fail("The {$attribute} field must be a rich document payload or plain text.");
                }
            },
        ];
    }

    private static function definition(string $resource, string $operation): array
    {
        $explicit = match ($resource) {
            'entities', 'timelines' => $operation === 'store' ? [
                'attributes' => [
                    'name' => ['required', 'string', 'max:255'],
                    'entity_type' => $resource === 'timelines'
                        ? ['nullable', 'string']
                        : ['required', 'string', 'in:'.implode(',', EntityType::ALL)],
                    'summary' => self::richDocumentRule(),
                    'public_title' => ['nullable', 'string', 'max:255'],
                    'public_summary' => self::richDocumentRule(),
                    'entity_sub_type' => ['nullable', 'string', 'max:255'],
                    'source_universes' => ['nullable', 'array'],
                    'source_universes.*' => ['string'],
                    'origin_type' => ['nullable', 'string'],
                    'canon_deviation' => ['nullable', 'string'],
                    'origin_notes' => self::richDocumentRule(),
                    'status' => ['nullable', 'string', 'in:'.implode(',', Entity::STATUSES)],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
            ] : [
                'attributes' => [
                    'name' => ['sometimes', 'string', 'max:255'],
                    'public_title' => ['nullable', 'string', 'max:255'],
                    'entity_type' => $resource === 'timelines'
                        ? ['nullable', 'string']
                        : ['sometimes', 'string', 'in:'.implode(',', EntityType::ALL)],
                    'entity_sub_type' => ['nullable', 'string', 'max:255'],
                    'summary' => self::richDocumentRule(),
                    'public_summary' => self::richDocumentRule(),
                    'status' => ['nullable', 'string'],
                    'type_status' => ['nullable', 'string', 'max:255'],
                    'power_tier_ceiling' => ['nullable', 'string'],
                    'power_tier_operating' => ['nullable', 'string'],
                    'power_tier_influence' => ['nullable', 'string'],
                    'source_universes' => ['nullable', 'array'],
                    'source_universes.*' => ['string'],
                    'origin_type' => ['nullable', 'string'],
                    'canon_deviation' => ['nullable', 'string'],
                    'origin_notes' => self::richDocumentRule(),
                    'control_state' => ['nullable', 'string'],
                    'persona_divergence' => ['nullable', 'string'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
            ],
            'entity-aliases' => $operation === 'store' ? [
                'attributes' => [
                    'alias' => ['required', 'string', 'max:255'],
                    'alias_type' => ['required', 'string', 'in:'.implode(',', EntityAlias::ALIAS_TYPES)],
                    'context' => ['nullable', 'string'],
                    'era_start' => ['nullable', 'string'],
                    'era_end' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'boolean'],
                    'known_by_entity_ids' => ['nullable', 'array'],
                    'known_by_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
                'relationships' => [
                    'entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'alias' => ['sometimes', 'string', 'max:255'],
                    'alias_type' => ['sometimes', 'string', 'in:'.implode(',', EntityAlias::ALIAS_TYPES)],
                    'context' => ['nullable', 'string'],
                    'era_start' => ['nullable', 'string'],
                    'era_end' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'boolean'],
                    'known_by_entity_ids' => ['nullable', 'array'],
                    'known_by_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
            ],
            'entity-notes' => $operation === 'store' ? [
                'attributes' => [
                    'note_label' => ['nullable', 'string', 'max:255'],
                    'content' => ['required', 'string'],
                    'sort_order' => ['nullable', 'integer'],
                ],
                'relationships' => [
                    'entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'note_label' => ['nullable', 'string', 'max:255'],
                    'content' => ['sometimes', 'string'],
                    'sort_order' => ['nullable', 'integer'],
                ],
            ],
            'entity-questions' => $operation === 'store' ? [
                'attributes' => [
                    'question' => ['required', 'string'],
                    'context' => ['nullable', 'string'],
                    'status' => ['nullable', 'string'],
                    'priority' => ['nullable', 'string'],
                    'linked_entity_ids' => ['nullable', 'array'],
                    'linked_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'linked_group_relationship_ids' => ['nullable', 'array'],
                    'linked_group_relationship_ids.*' => ['integer', 'exists:group_relationships,id'],
                    'sort_order' => ['nullable', 'integer'],
                ],
                'relationships' => [
                    'entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'question' => ['sometimes', 'string'],
                    'context' => ['nullable', 'string'],
                    'status' => ['nullable', 'string'],
                    'priority' => ['nullable', 'string'],
                    'resolution' => ['nullable', 'string'],
                    'linked_entity_ids' => ['nullable', 'array'],
                    'linked_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'linked_group_relationship_ids' => ['nullable', 'array'],
                    'linked_group_relationship_ids.*' => ['integer', 'exists:group_relationships,id'],
                    'sort_order' => ['nullable', 'integer'],
                ],
            ],
            'media-references' => $operation === 'store' ? [
                'attributes' => [
                    'title' => ['required', 'string', 'max:255'],
                    'description' => ['nullable', 'string'],
                    'media_type' => ['required', 'string', 'in:'.implode(',', MediaReference::MEDIA_TYPES)],
                    'purpose' => ['required', 'string', 'in:'.implode(',', MediaReference::PURPOSES)],
                    'file_path' => ['nullable', 'string'],
                    'url' => ['nullable', 'url'],
                    'file_name' => ['nullable', 'string', 'max:255'],
                    'file_extension' => ['nullable', 'string', 'max:50'],
                    'file_size_bytes' => ['nullable', 'integer', 'min:0'],
                    'mime_type' => ['nullable', 'string', 'max:255'],
                    'width_px' => ['nullable', 'integer', 'min:0'],
                    'height_px' => ['nullable', 'integer', 'min:0'],
                    'sort_order' => ['nullable', 'integer'],
                    'is_primary' => ['nullable', 'boolean'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
                'relationships' => [
                    'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'group_relationship_id' => ['nullable', 'integer', 'exists:group_relationships,id'],
                    'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
                    'meta_id' => ['nullable', 'integer', 'exists:meta,id'],
                    'timeline_entry_id' => ['nullable', 'integer', 'exists:timeline,id'],
                    'concurrency_group_id' => ['nullable', 'integer', 'exists:concurrency_groups,id'],
                    'source_canon_reference_id' => ['nullable', 'integer', 'exists:source_canon_reference,id'],
                ],
            ] : [
                'attributes' => [
                    'title' => ['sometimes', 'string', 'max:255'],
                    'description' => ['nullable', 'string'],
                    'media_type' => ['sometimes', 'string', 'in:'.implode(',', MediaReference::MEDIA_TYPES)],
                    'purpose' => ['sometimes', 'string', 'in:'.implode(',', MediaReference::PURPOSES)],
                    'file_path' => ['nullable', 'string'],
                    'url' => ['nullable', 'url'],
                    'file_name' => ['nullable', 'string', 'max:255'],
                    'file_extension' => ['nullable', 'string', 'max:50'],
                    'file_size_bytes' => ['nullable', 'integer', 'min:0'],
                    'mime_type' => ['nullable', 'string', 'max:255'],
                    'width_px' => ['nullable', 'integer', 'min:0'],
                    'height_px' => ['nullable', 'integer', 'min:0'],
                    'sort_order' => ['nullable', 'integer'],
                    'is_primary' => ['nullable', 'boolean'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
                'relationships' => [
                    'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'group_relationship_id' => ['nullable', 'integer', 'exists:group_relationships,id'],
                    'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
                    'meta_id' => ['nullable', 'integer', 'exists:meta,id'],
                    'timeline_entry_id' => ['nullable', 'integer', 'exists:timeline,id'],
                    'concurrency_group_id' => ['nullable', 'integer', 'exists:concurrency_groups,id'],
                    'source_canon_reference_id' => ['nullable', 'integer', 'exists:source_canon_reference,id'],
                ],
            ],
            'relationships' => $operation === 'store' ? [
                'attributes' => [
                    'relationship_type' => ['required', 'string', 'in:'.implode(',', RelationshipType::ALL)],
                    'direction' => ['nullable', 'string'],
                    'perspective_a' => ['nullable', 'array'],
                    'perspective_b' => ['nullable', 'array'],
                    'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
                    'is_active' => ['nullable', 'boolean'],
                    'perceived_type' => ['nullable', 'string'],
                    'true_type' => ['nullable', 'string'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
                'relationships' => [
                    'from_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'to_entity_id' => ['required', 'integer', 'exists:entities,id', 'different:from_entity_id'],
                ],
            ] : [
                'attributes' => [
                    'relationship_type' => ['sometimes', 'string', 'in:'.implode(',', RelationshipType::ALL)],
                    'direction' => ['nullable', 'string'],
                    'perspective_a' => ['nullable', 'array'],
                    'perspective_b' => ['nullable', 'array'],
                    'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
                    'charge_change_reason' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'boolean'],
                    'perceived_type' => ['nullable', 'string'],
                    'true_type' => ['nullable', 'string'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                ],
                'relationships' => [
                    'from_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'to_entity_id' => ['sometimes', 'integer', 'exists:entities,id', 'different:from_entity_id'],
                ],
            ],
            'group-relationships' => $operation === 'store' ? [
                'attributes' => [
                    'name' => ['required', 'string', 'max:255'],
                    'relationship_type' => ['required', 'string'],
                    'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
                    'is_active' => ['nullable', 'boolean'],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
            ] : [
                'attributes' => [
                    'name' => ['sometimes', 'string', 'max:255'],
                    'relationship_type' => ['sometimes', 'string'],
                    'current_tension_charge' => ['nullable', 'string', 'in:'.implode(',', TensionCharge::ALL)],
                    'charge_change_reason' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'boolean'],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
            ],
            'faction-memberships' => $operation === 'store' ? [
                'attributes' => [
                    'rank_or_role' => ['nullable', 'string'],
                    'membership_status' => ['nullable', 'string'],
                    'joined_era' => ['nullable', 'string'],
                    'left_era' => ['nullable', 'string'],
                    'departure_reason' => ['nullable', 'array'],
                    'is_undercover' => ['nullable', 'boolean'],
                    'public_membership_known' => ['nullable', 'boolean'],
                    'notes' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'faction_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'member_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'true_loyalty_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'recruited_by_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'rank_or_role' => ['nullable', 'string'],
                    'membership_status' => ['nullable', 'string'],
                    'joined_era' => ['nullable', 'string'],
                    'left_era' => ['nullable', 'string'],
                    'departure_reason' => ['nullable', 'array'],
                    'is_undercover' => ['nullable', 'boolean'],
                    'public_membership_known' => ['nullable', 'boolean'],
                    'notes' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'faction_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'member_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'true_loyalty_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'recruited_by_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'collections' => $operation === 'store' ? [
                'attributes' => [
                    'name' => ['required', 'string', 'max:255'],
                    'collection_type' => ['required', 'string', 'in:'.implode(',', Collection::TYPES)],
                    'collection_mode' => ['required', 'string', 'in:'.implode(',', Collection::MODES)],
                    'rules' => ['nullable', 'array'],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'parent_collection_id' => ['nullable', 'integer', 'exists:collections,id'],
                ],
            ] : [
                'attributes' => [
                    'name' => ['sometimes', 'string', 'max:255'],
                    'collection_type' => ['sometimes', 'string', 'in:'.implode(',', Collection::TYPES)],
                    'collection_mode' => ['sometimes', 'string', 'in:'.implode(',', Collection::MODES)],
                    'rules' => ['nullable', 'array'],
                    'completion_state' => ['nullable', 'string', 'in:'.implode(',', Collection::COMPLETION_STATES)],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'parent_collection_id' => ['nullable', 'integer', 'exists:collections,id'],
                ],
            ],
            'documents' => $operation === 'store' ? [
                'attributes' => [
                    'title' => ['required', 'string', 'max:255'],
                    'document_type' => ['required', 'string', 'in:'.implode(',', Document::DOCUMENT_TYPES)],
                    'document_authenticity' => ['nullable', 'string', 'in:'.implode(',', Document::AUTHENTICITY_STATES)],
                    'document_status' => ['nullable', 'string', 'in:'.implode(',', Document::DOCUMENT_STATUSES)],
                    'official_narrative' => ['nullable', 'array'],
                    'true_content' => ['nullable', 'array'],
                    'era_created' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'official_author_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'true_author_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'title' => ['sometimes', 'string', 'max:255'],
                    'document_type' => ['sometimes', 'string', 'in:'.implode(',', Document::DOCUMENT_TYPES)],
                    'document_authenticity' => ['nullable', 'string', 'in:'.implode(',', Document::AUTHENTICITY_STATES)],
                    'document_status' => ['nullable', 'string', 'in:'.implode(',', Document::DOCUMENT_STATUSES)],
                    'official_narrative' => ['nullable', 'array'],
                    'true_content' => ['nullable', 'array'],
                    'era_created' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'official_author_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'true_author_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'suppressed_by_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'canon-references' => $operation === 'store' ? [
                'attributes' => [
                    'universe' => ['required', 'string'],
                    'level' => ['required', 'string', 'in:'.implode(',', SourceCanonReference::LEVELS)],
                    'title' => ['required', 'string', 'max:255'],
                    'content' => ['nullable', 'array'],
                    'universe_priority' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::UNIVERSE_PRIORITIES)],
                    'research_status' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::RESEARCH_STATUSES)],
                    'research_confidence' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::RESEARCH_CONFIDENCES)],
                    'category_type' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::CATEGORY_TYPES)],
                    'element_type' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::ELEMENT_TYPES)],
                    'canon_disputed' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'parent_reference_id' => ['nullable', 'integer', 'exists:source_canon_reference,id'],
                    'au_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'crossover_entry_point_id' => ['nullable', 'integer', 'exists:crossover_entry_points,id'],
                ],
            ] : [
                'attributes' => [
                    'universe' => ['sometimes', 'string'],
                    'level' => ['sometimes', 'string', 'in:'.implode(',', SourceCanonReference::LEVELS)],
                    'title' => ['sometimes', 'string', 'max:255'],
                    'content' => ['nullable', 'array'],
                    'universe_priority' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::UNIVERSE_PRIORITIES)],
                    'research_status' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::RESEARCH_STATUSES)],
                    'research_confidence' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::RESEARCH_CONFIDENCES)],
                    'category_type' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::CATEGORY_TYPES)],
                    'element_type' => ['nullable', 'string', 'in:'.implode(',', SourceCanonReference::ELEMENT_TYPES)],
                    'canon_disputed' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'parent_reference_id' => ['nullable', 'integer', 'exists:source_canon_reference,id'],
                    'au_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'crossover_entry_point_id' => ['nullable', 'integer', 'exists:crossover_entry_points,id'],
                ],
            ],
            'crossover-entry-points' => $operation === 'store' ? [
                'attributes' => [
                    'source_universe' => ['required', 'string'],
                    'entry_mechanism' => ['nullable', 'array'],
                    'status' => ['nullable', 'string', 'in:'.implode(',', CrossoverEntryPoint::STATUSES)],
                ],
            ] : [
                'attributes' => [
                    'source_universe' => ['sometimes', 'string'],
                    'entry_mechanism' => ['nullable', 'array'],
                    'power_transition_rules' => ['nullable', 'array'],
                    'physical_transition_rules' => ['nullable', 'array'],
                    'memory_and_identity_rules' => ['nullable', 'array'],
                    'psychological_transition_rules' => ['nullable', 'array'],
                    'return_rules' => ['nullable', 'array'],
                    'status' => ['nullable', 'string', 'in:'.implode(',', CrossoverEntryPoint::STATUSES)],
                ],
            ],
            'secrets' => $operation === 'store' ? [
                'attributes' => [
                    'title' => ['required', 'string', 'max:255'],
                    'secret_content' => ['required', 'array'],
                    'secret_type' => ['required', 'string', 'in:'.implode(',', Secret::SECRET_TYPES)],
                    'subject_entity_ids' => ['nullable', 'array'],
                    'subject_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'holder_entity_ids' => ['nullable', 'array'],
                    'holder_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'known_by_entity_ids' => ['nullable', 'array'],
                    'known_by_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'exposure_risk' => ['nullable', 'string', 'in:'.implode(',', Secret::EXPOSURE_RISKS)],
                    'status' => ['nullable', 'string', 'in:'.implode(',', Secret::STATUSES)],
                ],
            ] : [
                'attributes' => [
                    'title' => ['sometimes', 'string'],
                    'secret_content' => ['nullable', 'array'],
                    'secret_type' => ['sometimes', 'string', 'in:'.implode(',', Secret::SECRET_TYPES)],
                    'subject_entity_ids' => ['nullable', 'array'],
                    'subject_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'holder_entity_ids' => ['nullable', 'array'],
                    'holder_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'known_by_entity_ids' => ['nullable', 'array'],
                    'known_by_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'exposure_risk' => ['nullable', 'string', 'in:'.implode(',', Secret::EXPOSURE_RISKS)],
                    'revelation_trigger' => ['nullable', 'string'],
                    'status' => ['nullable', 'string', 'in:'.implode(',', Secret::STATUSES)],
                ],
            ],
            'knowledge-states' => $operation === 'store' ? [
                'attributes' => [
                    'knowledge_type' => ['required', 'string', 'in:'.implode(',', KnowledgeState::KNOWLEDGE_TYPES)],
                    'knowledge_content' => ['nullable', 'array'],
                    'accuracy' => ['required', 'string', 'in:'.implode(',', KnowledgeState::ACCURACY_LEVELS)],
                    'current_belief_state' => ['required', 'string', 'in:'.implode(',', KnowledgeState::BELIEF_STATES)],
                    'acquired_through' => ['required', 'string', 'in:'.implode(',', KnowledgeState::ACQUISITION_METHODS)],
                    'acquired_at_era' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'knower_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'subject_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'subject_secret_id' => ['nullable', 'integer', 'exists:secrets,id'],
                    'subject_relationship_id' => ['nullable', 'integer', 'exists:relationships,id'],
                    'subject_group_relationship_id' => ['nullable', 'integer', 'exists:group_relationships,id'],
                    'subject_event_id' => ['nullable', 'integer', 'exists:timeline,id'],
                    'acquired_from_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'knowledge_type' => ['sometimes', 'string', 'in:'.implode(',', KnowledgeState::KNOWLEDGE_TYPES)],
                    'knowledge_content' => ['nullable', 'array'],
                    'accuracy' => ['sometimes', 'string', 'in:'.implode(',', KnowledgeState::ACCURACY_LEVELS)],
                    'current_belief_state' => ['sometimes', 'string', 'in:'.implode(',', KnowledgeState::BELIEF_STATES)],
                    'acquired_through' => ['sometimes', 'string', 'in:'.implode(',', KnowledgeState::ACQUISITION_METHODS)],
                    'acquired_at_era' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'knower_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'subject_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'subject_secret_id' => ['nullable', 'integer', 'exists:secrets,id'],
                    'subject_relationship_id' => ['nullable', 'integer', 'exists:relationships,id'],
                    'subject_group_relationship_id' => ['nullable', 'integer', 'exists:group_relationships,id'],
                    'subject_event_id' => ['nullable', 'integer', 'exists:timeline,id'],
                    'acquired_from_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'perception-states' => $operation === 'store' ? [
                'attributes' => [
                    'subject_type' => ['required', 'string', 'in:'.implode(',', PerceptionState::SUBJECT_TYPES)],
                    'subject_id' => ['required', 'integer'],
                    'true_state' => ['required', 'array'],
                    'perceived_state' => ['required', 'array'],
                    'divergence_level' => ['required', 'string', 'in:'.implode(',', PerceptionState::DIVERGENCE_LEVELS)],
                    'maintained_by_entity_ids' => ['nullable', 'array'],
                    'maintained_by_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'maintenance_method' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::MAINTENANCE_METHODS)],
                    'maintenance_effort' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::MAINTENANCE_EFFORTS)],
                    'revelation_risk' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::REVELATION_RISKS)],
                ],
            ] : [
                'attributes' => [
                    'subject_type' => ['sometimes', 'string', 'in:'.implode(',', PerceptionState::SUBJECT_TYPES)],
                    'subject_id' => ['sometimes', 'integer'],
                    'true_state' => ['nullable', 'array'],
                    'perceived_state' => ['nullable', 'array'],
                    'divergence_level' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::DIVERGENCE_LEVELS)],
                    'maintained_by_entity_ids' => ['nullable', 'array'],
                    'maintained_by_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'maintenance_method' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::MAINTENANCE_METHODS)],
                    'maintenance_effort' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::MAINTENANCE_EFFORTS)],
                    'revelation_risk' => ['nullable', 'string', 'in:'.implode(',', PerceptionState::REVELATION_RISKS)],
                ],
            ],
            'power-interactions' => $operation === 'store' ? [
                'attributes' => [
                    'interaction_name' => ['required', 'string', 'max:255'],
                    'description' => ['nullable', 'array'],
                    'directionality' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::DIRECTIONALITY_TYPES)],
                    'effects' => ['nullable', 'array'],
                    'interaction_scale' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::SCALE_TYPES)],
                    'knowledge_state' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::KNOWLEDGE_STATES)],
                    'danger_rating' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::DANGER_RATINGS)],
                    'proximity_required' => ['nullable', 'boolean'],
                    'source_universe_a' => ['nullable', 'string'],
                    'source_universe_b' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'system_a_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'system_b_entity_id' => ['required', 'integer', 'exists:entities,id', 'different:system_a_entity_id'],
                ],
            ] : [
                'attributes' => [
                    'interaction_name' => ['sometimes', 'string', 'max:255'],
                    'description' => ['nullable', 'array'],
                    'directionality' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::DIRECTIONALITY_TYPES)],
                    'effects' => ['nullable', 'array'],
                    'interaction_scale' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::SCALE_TYPES)],
                    'knowledge_state' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::KNOWLEDGE_STATES)],
                    'danger_rating' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::DANGER_RATINGS)],
                    'proximity_required' => ['nullable', 'boolean'],
                    'source_universe_a' => ['nullable', 'string'],
                    'source_universe_b' => ['nullable', 'string'],
                    'unresolved_flag' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'system_a_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'system_b_entity_id' => ['sometimes', 'integer', 'exists:entities,id', 'different:system_a_entity_id'],
                ],
            ],
            'travel-routes' => $operation === 'store' ? [
                'attributes' => [
                    'route_type' => ['required', 'string', 'in:'.implode(',', TravelRoute::ROUTE_TYPES)],
                    'standard_duration' => ['nullable', 'string'],
                    'method_variants' => ['nullable', 'array'],
                    'bidirectional' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'origin_location_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'destination_location_entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'route_type' => ['sometimes', 'string', 'in:'.implode(',', TravelRoute::ROUTE_TYPES)],
                    'standard_duration' => ['nullable', 'string'],
                    'method_variants' => ['nullable', 'array'],
                    'bidirectional' => ['nullable', 'boolean'],
                    'hazards' => ['nullable', 'array'],
                    'is_active' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'origin_location_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'destination_location_entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                ],
            ],
            'location-containment' => $operation === 'store' ? [
                'attributes' => [
                    'containment_type' => ['required', 'string', 'in:'.implode(',', LocationContainment::CONTAINMENT_TYPES)],
                    'era_start' => ['nullable', 'string'],
                    'era_end' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'boolean'],
                    'notes' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'child_location_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'parent_location_entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'containment_type' => ['sometimes', 'string', 'in:'.implode(',', LocationContainment::CONTAINMENT_TYPES)],
                    'era_start' => ['nullable', 'string'],
                    'era_end' => ['nullable', 'string'],
                    'is_active' => ['nullable', 'boolean'],
                    'notes' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'child_location_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'parent_location_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'location-control-records' => $operation === 'store' ? [
                'attributes' => [
                    'control_type' => ['required', 'string', 'in:'.implode(',', LocationControlHistory::CONTROL_TYPES)],
                    'control_start_era' => ['nullable', 'string'],
                    'control_end_era' => ['nullable', 'string'],
                    'how_control_was_established' => ['nullable', 'array'],
                    'how_control_ended' => ['nullable', 'array'],
                    'resistance_level' => ['nullable', 'string', 'in:'.implode(',', LocationControlHistory::RESISTANCE_LEVELS)],
                    'notes' => ['nullable', 'array'],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'location_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'controlling_entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'resistance_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'control_type' => ['sometimes', 'string', 'in:'.implode(',', LocationControlHistory::CONTROL_TYPES)],
                    'control_start_era' => ['nullable', 'string'],
                    'control_end_era' => ['nullable', 'string'],
                    'how_control_was_established' => ['nullable', 'array'],
                    'how_control_ended' => ['nullable', 'array'],
                    'resistance_level' => ['nullable', 'string', 'in:'.implode(',', LocationControlHistory::RESISTANCE_LEVELS)],
                    'notes' => ['nullable', 'array'],
                    'is_current' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'location_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'controlling_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'resistance_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'meta' => $operation === 'store' ? [
                'attributes' => [
                    'title' => ['required', 'string', 'max:255'],
                    'category' => ['required', 'string', 'in:'.implode(',', Meta::CATEGORIES)],
                    'meta_note_type' => ['required', 'string', 'in:'.implode(',', Meta::NOTE_TYPES)],
                    'content' => ['nullable', 'array'],
                    'sense_sight' => ['nullable', 'string'],
                    'sense_sound' => ['nullable', 'string'],
                    'sense_smell' => ['nullable', 'string'],
                    'sense_taste' => ['nullable', 'string'],
                    'sense_touch' => ['nullable', 'string'],
                    'sense_magical' => ['nullable', 'string'],
                    'emotional_register' => ['nullable', 'string'],
                    'symbol_name' => ['nullable', 'string', 'max:255'],
                    'symbol_usage_context' => ['nullable', 'string'],
                    'symbol_associated_entity_ids' => ['nullable', 'array'],
                    'symbol_associated_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'symbol_scope' => ['nullable', 'string', 'in:'.implode(',', Meta::SYMBOL_SCOPES)],
                    'priority' => ['nullable', 'string', 'in:'.implode(',', Meta::PRIORITIES)],
                    'action_status' => ['nullable', 'string', 'in:'.implode(',', Meta::ACTION_STATUSES)],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'symbol_origin_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'title' => ['sometimes', 'string', 'max:255'],
                    'category' => ['sometimes', 'string', 'in:'.implode(',', Meta::CATEGORIES)],
                    'meta_note_type' => ['sometimes', 'string', 'in:'.implode(',', Meta::NOTE_TYPES)],
                    'content' => ['nullable', 'array'],
                    'priority' => ['nullable', 'string', 'in:'.implode(',', Meta::PRIORITIES)],
                    'action_status' => ['nullable', 'string', 'in:'.implode(',', Meta::ACTION_STATUSES)],
                    'resolution_notes' => ['nullable', 'array'],
                    'resolved_at' => ['nullable', 'date'],
                    'sense_sight' => ['nullable', 'string'],
                    'sense_sound' => ['nullable', 'string'],
                    'sense_smell' => ['nullable', 'string'],
                    'sense_taste' => ['nullable', 'string'],
                    'sense_touch' => ['nullable', 'string'],
                    'sense_magical' => ['nullable', 'string'],
                    'emotional_register' => ['nullable', 'string'],
                    'symbol_name' => ['nullable', 'string', 'max:255'],
                    'symbol_usage_context' => ['nullable', 'string'],
                    'symbol_associated_entity_ids' => ['nullable', 'array'],
                    'symbol_associated_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'symbol_scope' => ['nullable', 'string', 'in:'.implode(',', Meta::SYMBOL_SCOPES)],
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'symbol_origin_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'pipeline-items' => $operation === 'store' ? [
                'attributes' => [
                    'title' => ['required', 'string', 'max:255'],
                    'pipeline_type' => ['required', 'string', 'in:'.implode(',', PipelineItem::PIPELINE_TYPES)],
                    'pipeline_stage' => ['nullable', 'string', 'in:'.implode(',', PipelineItem::PIPELINE_STAGES)],
                    'emotional_beat' => ['nullable', 'string', 'max:255'],
                    'content' => self::richDocumentRule(),
                    'narrative_purpose' => self::richDocumentRule(),
                    'arc_stage' => ['nullable', 'string', 'max:255'],
                    'arc_notes' => self::richDocumentRule(),
                    'notes' => self::richDocumentRule(),
                    'visibility' => ['nullable', 'string'],
                    'content_classification' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'parent_pipeline_item_id' => ['nullable', 'integer', 'exists:writing_pipeline,id'],
                    'pov_character_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'location_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'tracked_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'meta_id' => ['nullable', 'integer', 'exists:meta,id'],
                    'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'title' => ['sometimes', 'string'],
                    'pipeline_type' => ['sometimes', 'string', 'in:'.implode(',', PipelineItem::PIPELINE_TYPES)],
                    'pipeline_stage' => ['nullable', 'string', 'in:'.implode(',', PipelineItem::PIPELINE_STAGES)],
                    'content' => self::richDocumentRule(),
                    'word_count' => ['nullable', 'integer'],
                    'reading_time_minutes' => ['nullable', 'integer'],
                    'emotional_beat' => ['nullable', 'string'],
                    'narrative_purpose' => self::richDocumentRule(),
                    'arc_stage' => ['nullable', 'string'],
                    'arc_notes' => self::richDocumentRule(),
                    'notes' => self::richDocumentRule(),
                    'add_to_voice_samples' => ['nullable', 'boolean'],
                ],
                'relationships' => [
                    'pov_character_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'location_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'tracked_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'glossary' => $operation === 'store' ? [
                'attributes' => [
                    'term' => ['required', 'string', 'max:255'],
                    'usage_context' => ['required', 'string', 'in:'.implode(',', Glossary::USAGE_CONTEXTS)],
                    'definition' => ['required', 'array'],
                    'origin_universe' => ['nullable', 'string'],
                    'era_introduced' => ['nullable', 'string'],
                    'term_status' => ['nullable', 'string', 'in:'.implode(',', Glossary::TERM_STATUSES)],
                    'related_term_ids' => ['nullable', 'array'],
                    'related_term_ids.*' => ['integer'],
                ],
                'relationships' => [
                    'suppressed_by_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'first_appearance_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'term' => ['sometimes', 'string', 'max:255'],
                    'usage_context' => ['sometimes', 'string', 'in:'.implode(',', Glossary::USAGE_CONTEXTS)],
                    'definition' => ['nullable', 'array'],
                    'origin_universe' => ['nullable', 'string'],
                    'era_introduced' => ['nullable', 'string'],
                    'extended_notes' => ['nullable', 'array'],
                    'term_status' => ['nullable', 'string', 'in:'.implode(',', Glossary::TERM_STATUSES)],
                    'suppression_notes' => ['nullable', 'array'],
                    'related_term_ids' => ['nullable', 'array'],
                    'related_term_ids.*' => ['integer'],
                ],
                'relationships' => [
                    'suppressed_by_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'first_appearance_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'session-logs' => $operation === 'store' ? [
                'attributes' => [
                    'title' => ['required', 'string', 'max:255'],
                    'session_date' => ['nullable', 'date'],
                    'external_tool' => ['required', 'string', 'in:'.implode(',', SessionLog::EXTERNAL_TOOLS)],
                    'focus_entity_ids' => ['nullable', 'array'],
                    'focus_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'focus_group_relationship_ids' => ['nullable', 'array'],
                    'focus_group_relationship_ids.*' => ['integer', 'exists:group_relationships,id'],
                    'focus_collection_ids' => ['nullable', 'array'],
                    'focus_collection_ids.*' => ['integer', 'exists:collections,id'],
                    'focus_description' => ['nullable', 'string', 'max:255'],
                    'decisions_made' => ['nullable', 'array'],
                    'changes_applied' => ['nullable', 'array'],
                    'open_threads' => ['nullable', 'array'],
                    'session_significance' => ['nullable', 'string', 'in:'.implode(',', SessionLog::SIGNIFICANCE_LEVELS)],
                    'notes' => ['nullable', 'array'],
                ],
            ] : [
                'attributes' => [
                    'title' => ['sometimes', 'string'],
                    'session_date' => ['nullable', 'date'],
                    'external_tool' => ['sometimes', 'string', 'in:'.implode(',', SessionLog::EXTERNAL_TOOLS)],
                    'focus_entity_ids' => ['nullable', 'array'],
                    'focus_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'focus_group_relationship_ids' => ['nullable', 'array'],
                    'focus_group_relationship_ids.*' => ['integer', 'exists:group_relationships,id'],
                    'focus_collection_ids' => ['nullable', 'array'],
                    'focus_collection_ids.*' => ['integer', 'exists:collections,id'],
                    'focus_description' => ['nullable', 'string'],
                    'decisions_made' => ['nullable', 'array'],
                    'changes_applied' => ['nullable', 'array'],
                    'open_threads' => ['nullable', 'array'],
                    'session_significance' => ['nullable', 'string', 'in:'.implode(',', SessionLog::SIGNIFICANCE_LEVELS)],
                    'notes' => ['nullable', 'array'],
                ],
            ],
            'notion-sync-mappings' => $operation === 'store' ? [
                'attributes' => [
                    'sync_resource' => ['required', 'string', 'max:255'],
                    'notion_page_id' => ['required', 'string', 'max:255'],
                    'notion_parent_database_id' => ['nullable', 'string', 'max:255'],
                    'local_model_type' => ['required', 'string', 'max:255'],
                    'local_model_id' => ['required', 'integer', 'min:1'],
                    'notion_last_edited_at' => ['nullable', 'date'],
                    'last_synced_at' => ['nullable', 'date'],
                    'last_payload_hash' => ['nullable', 'string', 'max:255'],
                ],
            ] : [
                'attributes' => [
                    'sync_resource' => ['sometimes', 'string', 'max:255'],
                    'notion_page_id' => ['sometimes', 'string', 'max:255'],
                    'notion_parent_database_id' => ['nullable', 'string', 'max:255'],
                    'local_model_type' => ['sometimes', 'string', 'max:255'],
                    'local_model_id' => ['sometimes', 'integer', 'min:1'],
                    'notion_last_edited_at' => ['nullable', 'date'],
                    'last_synced_at' => ['nullable', 'date'],
                    'last_payload_hash' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'timeline-entries' => [
                'attributes' => [
                    'entry_label' => ['nullable', 'string', 'max:255'],
                    'au_date' => ['nullable', 'string'],
                    'source_date' => ['nullable', 'string'],
                    'source_date_universe' => ['nullable', 'string'],
                    'timeline_position' => ['nullable', 'integer'],
                    'primordial_era' => ['nullable', 'boolean'],
                    'time_density' => ['nullable', 'string', 'in:'.implode(',', Timeline::TIME_DENSITY_LEVELS)],
                    'causality_type' => ['nullable', 'string', 'in:'.implode(',', Timeline::CAUSALITY_TYPES)],
                    'causality_notes' => ['nullable', 'string'],
                    'event_significance' => ['nullable', 'string', 'in:'.implode(',', Timeline::EVENT_SIGNIFICANCE_LEVELS)],
                    'is_atemporal' => ['nullable', 'boolean'],
                    'public_narrative' => ['nullable', 'array'],
                    'true_narrative' => ['nullable', 'array'],
                    'narrative_divergence' => ['nullable', 'string', 'in:'.implode(',', Timeline::NARRATIVE_DIVERGENCE_LEVELS)],
                    'truth_revealed_at_era' => ['nullable', 'string'],
                    'temporal_certainty' => ['nullable', 'string', 'in:'.implode(',', Timeline::TEMPORAL_CERTAINTY_LEVELS)],
                ],
                'relationships' => [
                    'timeline_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'event_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'era_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'concurrency_group_id' => ['nullable', 'integer', 'exists:concurrency_groups,id'],
                ],
            ],
            'character-states' => $operation === 'store' ? [
                'attributes' => [
                    'au_date' => ['nullable', 'string'],
                    'source_date' => ['nullable', 'string'],
                    'snapshot_label' => ['nullable', 'string', 'max:255'],
                    'snapshot_significance' => ['nullable', 'string', 'in:'.implode(',', CharacterStateTracker::SNAPSHOT_SIGNIFICANCE_LEVELS)],
                    'significance_reason' => ['nullable', 'string'],
                    'current_trauma_profile' => self::richDocumentRule(),
                    'active_psychological_patterns' => self::richDocumentRule(),
                    'core_wound' => ['nullable', 'string'],
                    'current_desire' => ['nullable', 'string'],
                    'current_fear' => ['nullable', 'string'],
                    'shadow_self' => ['nullable', 'string'],
                    'true_self' => ['nullable', 'string'],
                    'performed_self' => ['nullable', 'string'],
                    'current_stability_level' => ['nullable', 'string', 'in:'.implode(',', CharacterStateTracker::STABILITY_LEVELS)],
                    'mask_integrity' => ['nullable', 'string', 'in:'.implode(',', CharacterStateTracker::MASK_INTEGRITY_LEVELS)],
                    'current_power_tier_operating' => ['nullable', 'string'],
                    'current_power_tier_influence' => ['nullable', 'string'],
                    'timeline_position' => ['nullable', 'integer'],
                    'key_relationships_summary' => ['nullable', 'array'],
                    'active_group_relationship_ids' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'entity_id' => ['required', 'integer', 'exists:entities,id'],
                    'timeline_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'era_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ] : [
                'attributes' => [
                    'au_date' => ['nullable', 'string'],
                    'source_date' => ['nullable', 'string'],
                    'snapshot_label' => ['nullable', 'string', 'max:255'],
                    'snapshot_significance' => ['nullable', 'string', 'in:'.implode(',', CharacterStateTracker::SNAPSHOT_SIGNIFICANCE_LEVELS)],
                    'significance_reason' => ['nullable', 'string'],
                    'current_trauma_profile' => self::richDocumentRule(),
                    'active_psychological_patterns' => self::richDocumentRule(),
                    'core_wound' => ['nullable', 'string'],
                    'current_desire' => ['nullable', 'string'],
                    'current_fear' => ['nullable', 'string'],
                    'shadow_self' => ['nullable', 'string'],
                    'true_self' => ['nullable', 'string'],
                    'performed_self' => ['nullable', 'string'],
                    'current_stability_level' => ['nullable', 'string', 'in:'.implode(',', CharacterStateTracker::STABILITY_LEVELS)],
                    'mask_integrity' => ['nullable', 'string', 'in:'.implode(',', CharacterStateTracker::MASK_INTEGRITY_LEVELS)],
                    'current_power_tier_operating' => ['nullable', 'string'],
                    'current_power_tier_influence' => ['nullable', 'string'],
                    'timeline_position' => ['nullable', 'integer'],
                    'key_relationships_summary' => ['nullable', 'array'],
                    'active_group_relationship_ids' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'entity_id' => ['sometimes', 'integer', 'exists:entities,id'],
                    'timeline_id' => ['nullable', 'integer', 'exists:entities,id'],
                    'era_entity_id' => ['nullable', 'integer', 'exists:entities,id'],
                ],
            ],
            'concurrency-groups' => $operation === 'store' ? [
                'attributes' => [
                    'name' => ['required', 'string', 'max:255'],
                    'au_date' => ['nullable', 'string'],
                    'description' => ['nullable', 'array'],
                    'narrative_significance' => ['nullable', 'string', 'in:'.implode(',', ConcurrencyGroup::SIGNIFICANCE_LEVELS)],
                ],
            ] : [
                'attributes' => [
                    'name' => ['sometimes', 'string'],
                    'au_date' => ['nullable', 'string'],
                    'description' => ['nullable', 'array'],
                    'narrative_significance' => ['nullable', 'string', 'in:'.implode(',', ConcurrencyGroup::SIGNIFICANCE_LEVELS)],
                ],
            ],
            default => null,
        };

        if ($explicit === null) {
            return self::genericDefinition($resource, $operation);
        }

        return self::mergeDefinition(self::genericDefinition($resource, $operation), $explicit);
    }

    private static function actionDefinition(string $action): array
    {
        return match ($action) {
            'entity-save-version' => [
                'attributes' => [
                    'version_label' => ['nullable', 'string', 'max:255'],
                    'what_changed' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_string($value) && ! is_array($value)) {
                            $fail("The {$attribute} field must be a string or rich document payload.");
                        }
                    }],
                    'why_changed' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_string($value) && ! is_array($value)) {
                            $fail("The {$attribute} field must be a string or rich document payload.");
                        }
                    }],
                    'valid_from_era' => ['nullable', 'string'],
                    'version_zero_confidence' => ['nullable', 'string', 'in:rough,developing,solid,verified'],
                    'version_zero_notes' => ['nullable', 'string'],
                    'visibility' => ['nullable', 'string', 'in:'.implode(',', VisibilityLevel::ALL)],
                    'content_classification' => ['nullable', 'string', 'in:'.implode(',', ContentClassification::ALL)],
                    'is_version_zero' => ['nullable', 'boolean'],
                ],
            ],
            'relationship-tension-charge', 'group-relationship-tension-charge' => [
                'attributes' => [
                    'new_charge' => ['required', 'string', 'in:'.implode(',', TensionCharge::ALL)],
                    'reason' => ['nullable', 'string'],
                ],
            ],
            'group-relationship-add-member' => [
                'attributes' => [
                    'role_in_group' => ['nullable', 'string'],
                    'joined_era' => ['nullable', 'string'],
                    'participation_notes' => ['nullable', 'array'],
                ],
                'relationships' => [
                    'entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ],
            'group-relationship-remove-member' => [
                'attributes' => [
                    'left_era' => ['nullable', 'string'],
                    'departure_notes' => ['nullable', 'array'],
                ],
            ],
            'faction-membership-terminate' => [
                'attributes' => [
                    'left_era' => ['nullable', 'string'],
                    'departure_reason' => ['nullable', 'array'],
                ],
            ],
            'timeline-place-event' => [
                'attributes' => self::definition('timeline-entries', 'update')['attributes'],
                'relationships' => array_merge(
                    self::definition('timeline-entries', 'update')['relationships'],
                    ['event_entity_id' => ['required', 'integer', 'exists:entities,id']],
                ),
            ],
            'timeline-update-event' => self::definition('timeline-entries', 'update'),
            'power-interaction-resolve' => [
                'attributes' => [
                    'resolution_notes' => ['nullable', 'array'],
                    'knowledge_state' => ['nullable', 'string', 'in:'.implode(',', PowerInteraction::KNOWLEDGE_STATES)],
                ],
            ],
            'power-interaction-instance' => [
                'attributes' => [
                    'involved_entity_ids' => ['nullable', 'array'],
                    'involved_entity_ids.*' => ['integer', 'exists:entities,id'],
                    'outcome_match' => ['required', 'string', 'in:'.implode(',', PowerInteractionInstance::OUTCOME_MATCHES)],
                    'outcome_notes' => ['nullable', 'array'],
                    'observed_at_era' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'event_entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ],
            'knowledge-state-act-on' => [
                'attributes' => [
                    'action_notes' => ['nullable', 'array'],
                ],
            ],
            'secret-expose' => [
                'attributes' => [
                    'era' => ['required', 'string'],
                    'exposure_level' => ['nullable', 'string', 'in:partially_exposed,fully_exposed'],
                ],
            ],
            'secret-known-by', 'secret-holders', 'perception-immune', 'meta-link-entity' => [
                'relationships' => [
                    'entity_id' => ['required', 'integer', 'exists:entities,id'],
                ],
            ],
            'perception-collapse' => [
                'attributes' => [
                    'era' => ['required', 'string'],
                ],
            ],
            'meta-resolve' => [
                'attributes' => [
                    'resolution_notes' => ['nullable', 'array'],
                ],
            ],
            'meta-supersede' => [
                'attributes' => [
                    'supersession_reason' => ['nullable', 'string'],
                ],
                'relationships' => [
                    'superseded_by_meta_id' => ['required', 'integer', 'exists:meta,id'],
                ],
            ],
            'pipeline-resolve' => [
                'attributes' => [
                    'resolution_notes' => ['nullable', 'array'],
                ],
            ],
            default => [],
        };
    }

    private static function genericDefinition(string $resource, string $operation): array
    {
        $modelClass = ApiResourceRegistry::modelClass($resource);
        /** @var Model $model */
        $model = new $modelClass;
        $casts = $model->getCasts();
        $attributes = [];

        foreach ($model->getFillable() as $field) {
            $attributes[$field] = self::genericFieldRules($field, $casts[$field] ?? null, $operation);
        }

        return ['attributes' => $attributes];
    }

    private static function mergeDefinition(array $generic, array $explicit): array
    {
        $merged = [
            'attributes' => array_merge($generic['attributes'] ?? [], $explicit['attributes'] ?? []),
            'relationships' => array_merge($generic['relationships'] ?? [], $explicit['relationships'] ?? []),
        ];

        foreach (array_keys($explicit['relationships'] ?? []) as $relationshipField) {
            unset($merged['attributes'][$relationshipField]);
        }

        return $merged;
    }

    private static function genericFieldRules(string $field, mixed $cast, string $operation): array
    {
        $rules = [];

        if ($operation === 'update') {
            $rules[] = 'sometimes';
        } else {
            $rules[] = 'nullable';
        }

        return match ($cast) {
            'array' => [...$rules, 'array'],
            'boolean' => [...$rules, 'boolean'],
            'integer' => [...$rules, 'integer'],
            'datetime', 'date' => [...$rules, 'date'],
            default => [...$rules],
        };
    }

    private static function flatten(array $definition): array
    {
        return array_merge(
            $definition['attributes'] ?? [],
            $definition['relationships'] ?? [],
        );
    }

    private static function prefix(array $definition): array
    {
        $hasPayloadRules = ! empty($definition['attributes'] ?? []) || ! empty($definition['relationships'] ?? []);
        $rules = [
            'data' => [$hasPayloadRules ? 'required' : 'nullable', 'array'],
            'data.attributes' => ['nullable', 'array'],
            'data.relationships' => ['nullable', 'array'],
        ];

        foreach ($definition['attributes'] ?? [] as $field => $fieldRules) {
            $rules["data.attributes.{$field}"] = $fieldRules;
        }

        foreach ($definition['relationships'] ?? [] as $field => $fieldRules) {
            $rules["data.relationships.{$field}"] = $fieldRules;
        }

        return $rules;
    }
}
