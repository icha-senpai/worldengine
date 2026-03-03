<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\EntityType;

class CompletionScoreCalculator
{
    // --- WEIGHT DEFINITIONS ---
    // Total weights must sum to 100
    // Weights are shared across all entity types
    // Type-specific bonuses applied on top

    const WEIGHTS = [
        'summary'          => 15,
        'true_nature'      => 20,
        'has_aliases'      => 5,
        'has_relationships'=> 15,
        'has_timeline_entries' => 15,
        'has_state_snapshots'  => 10,
        'has_documents'    => 5,
        'has_media'        => 5,
        'has_attributes'   => 10,
    ];

    // Additional type-specific checks
    // These contribute to the has_attributes weight
    const TYPE_SPECIFIC = [
        EntityType::CHARACTER => [
            'power_tier_ceiling'   => 5,
            'power_tier_operating' => 5,
            'source_universes'     => 5,
        ],
        EntityType::CONSTRUCTED_INTEL => [
            'iteration_number'  => 5,
            'source_entity_id'  => 5,
            'power_tier_ceiling' => 5,
        ],
        EntityType::LOCATION => [
            'space_type'    => 5,
            'coordinates'   => 5,
        ],
        EntityType::FACTION => [
            'control_state' => 5,
            'source_universes' => 5,
        ],
    ];

    public function calculate(Entity $entity): int
    {
        $score = 0;

        // Core field checks
        if (!empty($entity->summary)) {
            $score += self::WEIGHTS['summary'];
        }

        if (!empty($entity->true_nature)) {
            $score += self::WEIGHTS['true_nature'];
        }

        // Completion flag checks
        if ($entity->has_aliases) {
            $score += self::WEIGHTS['has_aliases'];
        }

        if ($entity->has_relationships) {
            $score += self::WEIGHTS['has_relationships'];
        }

        if ($entity->has_timeline_entries) {
            $score += self::WEIGHTS['has_timeline_entries'];
        }

        if ($entity->has_state_snapshots) {
            $score += self::WEIGHTS['has_state_snapshots'];
        }

        if ($entity->has_documents) {
            $score += self::WEIGHTS['has_documents'];
        }

        if ($entity->has_media) {
            $score += self::WEIGHTS['has_media'];
        }

        if ($entity->has_attributes) {
            $score += self::WEIGHTS['has_attributes'];
        }

        // Type-specific bonus checks
        // Distributed within remaining headroom
        $typeSpecific = self::TYPE_SPECIFIC[$entity->entity_type] ?? [];

        foreach ($typeSpecific as $field => $bonus) {
            $value = $entity->$field ?? null;

            if (!empty($value)) {
                $score += $bonus;
            }
        }

        // Cap at 100
        return min(100, $score);
    }

    // Returns a breakdown of what is contributing to the score
    // and what is missing — used by the entity card completion panel
    public function breakdown(Entity $entity): array
    {
        $earned  = [];
        $missing = [];

        $checks = [
            'summary'              => !empty($entity->summary),
            'true_nature'          => !empty($entity->true_nature),
            'aliases'              => $entity->has_aliases,
            'relationships'        => $entity->has_relationships,
            'timeline_entries'     => $entity->has_timeline_entries,
            'state_snapshots'      => $entity->has_state_snapshots,
            'documents'            => $entity->has_documents,
            'media'                => $entity->has_media,
            'attributes'           => $entity->has_attributes,
        ];

        foreach ($checks as $label => $complete) {
            if ($complete) {
                $earned[]  = $label;
            } else {
                $missing[] = $label;
            }
        }

        // Type-specific
        $typeSpecific = self::TYPE_SPECIFIC[$entity->entity_type] ?? [];

        foreach ($typeSpecific as $field => $bonus) {
            $value = $entity->$field ?? null;

            if (!empty($value)) {
                $earned[]  = $field;
            } else {
                $missing[] = $field;
            }
        }

        return [
            'score'   => $this->calculate($entity),
            'earned'  => $earned,
            'missing' => $missing,
        ];
    }
}
