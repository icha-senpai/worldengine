<?php

namespace App\Domain\Identity\ValueObjects;

use InvalidArgumentException;

final class EntityType
{
    // --- CATEGORIES AND THEIR TYPES ---

    // People
    const CHARACTER           = 'character';
    const HISTORICAL_FIGURE   = 'historical_figure';
    const CONSTRUCTED_INTEL   = 'constructed_intelligence';

    // Places
    const LOCATION            = 'location';
    const DIMENSION           = 'dimension';
    const PLANE               = 'plane';
    const REALM               = 'realm';

    // Groups
    const FACTION             = 'faction';
    const ORGANIZATION        = 'organization';
    const GOVERNMENT          = 'government';
    const MOVEMENT            = 'movement';

    // Supernatural
    const DEITY               = 'deity';
    const COSMIC_ENTITY       = 'cosmic_entity';
    const SPIRIT              = 'spirit';
    const CREATURE            = 'creature';

    // Objects
    const ARTIFACT            = 'artifact';
    const WEAPON              = 'weapon';
    const RELIC               = 'relic';
    const VEHICLE             = 'vehicle';

    // Concepts
    const MAGIC_SYSTEM        = 'magic_system';
    const POWER_SYSTEM        = 'power_system';
    const TECHNOLOGY          = 'technology';
    const CONCEPT             = 'concept';
    const PHILOSOPHY          = 'philosophy';

    // Events
    const EVENT               = 'event';
    const CONFLICT            = 'conflict';
    const RITUAL              = 'ritual';
    const PHENOMENON          = 'phenomenon';

    // Time
    const ERA                 = 'era';
    const CYCLE               = 'cycle';
    const TIMELINE            = 'timeline';

    // Species and biology
    const SPECIES             = 'species';
    const BLOODLINE           = 'bloodline';

    // Narrative
    const PROPHECY            = 'prophecy';
    const LEGEND              = 'legend';
    const MYTH                = 'myth';

    // Cosmological
    const COSMOLOGICAL_FORCE  = 'cosmological_force';
    const UNIVERSAL_LAW       = 'universal_law';
    const CONVERGENCE_POINT   = 'convergence_point';
    const VOID_ENTITY         = 'void_entity';

    // --- CATEGORY GROUPINGS ---
    // Useful for UI grouping and validation

    const CATEGORIES = [
        'people' => [
            self::CHARACTER,
            self::HISTORICAL_FIGURE,
            self::CONSTRUCTED_INTEL,
        ],
        'places' => [
            self::LOCATION,
            self::DIMENSION,
            self::PLANE,
            self::REALM,
        ],
        'groups' => [
            self::FACTION,
            self::ORGANIZATION,
            self::GOVERNMENT,
            self::MOVEMENT,
        ],
        'supernatural' => [
            self::DEITY,
            self::COSMIC_ENTITY,
            self::SPIRIT,
            self::CREATURE,
        ],
        'objects' => [
            self::ARTIFACT,
            self::WEAPON,
            self::RELIC,
            self::VEHICLE,
        ],
        'concepts' => [
            self::MAGIC_SYSTEM,
            self::POWER_SYSTEM,
            self::TECHNOLOGY,
            self::CONCEPT,
            self::PHILOSOPHY,
        ],
        'events' => [
            self::EVENT,
            self::CONFLICT,
            self::RITUAL,
            self::PHENOMENON,
        ],
        'time' => [
            self::ERA,
            self::CYCLE,
            self::TIMELINE,
        ],
        'species' => [
            self::SPECIES,
            self::BLOODLINE,
        ],
        'narrative' => [
            self::PROPHECY,
            self::LEGEND,
            self::MYTH,
        ],
        'cosmological' => [
            self::COSMOLOGICAL_FORCE,
            self::UNIVERSAL_LAW,
            self::CONVERGENCE_POINT,
            self::VOID_ENTITY,
        ],
    ];

    // Flat list of all valid values
    const ALL = [
        self::CHARACTER,
        self::HISTORICAL_FIGURE,
        self::CONSTRUCTED_INTEL,
        self::LOCATION,
        self::DIMENSION,
        self::PLANE,
        self::REALM,
        self::FACTION,
        self::ORGANIZATION,
        self::GOVERNMENT,
        self::MOVEMENT,
        self::DEITY,
        self::COSMIC_ENTITY,
        self::SPIRIT,
        self::CREATURE,
        self::ARTIFACT,
        self::WEAPON,
        self::RELIC,
        self::VEHICLE,
        self::MAGIC_SYSTEM,
        self::POWER_SYSTEM,
        self::TECHNOLOGY,
        self::CONCEPT,
        self::PHILOSOPHY,
        self::EVENT,
        self::CONFLICT,
        self::RITUAL,
        self::PHENOMENON,
        self::ERA,
        self::CYCLE,
        self::TIMELINE,
        self::SPECIES,
        self::BLOODLINE,
        self::PROPHECY,
        self::LEGEND,
        self::MYTH,
        self::COSMOLOGICAL_FORCE,
        self::UNIVERSAL_LAW,
        self::CONVERGENCE_POINT,
        self::VOID_ENTITY,
    ];

    // Types that can have spatial fields populated
    const SPATIAL_TYPES = [
        self::LOCATION,
        self::DIMENSION,
        self::PLANE,
        self::REALM,
        self::CONVERGENCE_POINT,
    ];

    // Types that can be timeline_id or era_entity_id targets
    const TEMPORAL_TYPES = [
        self::TIMELINE,
        self::ERA,
        self::CYCLE,
    ];

    // Types that are valid as event_entity_id targets
    const EVENT_TYPES = [
        self::EVENT,
        self::CONFLICT,
        self::RITUAL,
        self::PHENOMENON,
    ];

    // Types that can be faction_entity_id in faction_memberships
    const FACTION_TYPES = [
        self::FACTION,
        self::ORGANIZATION,
        self::GOVERNMENT,
        self::MOVEMENT,
    ];

    // Types that have power tiers
    const POWERED_TYPES = [
        self::CHARACTER,
        self::HISTORICAL_FIGURE,
        self::CONSTRUCTED_INTEL,
        self::DEITY,
        self::COSMIC_ENTITY,
        self::SPIRIT,
        self::CREATURE,
        self::COSMOLOGICAL_FORCE,
        self::VOID_ENTITY,
    ];

    // --- VALUE OBJECT IMPLEMENTATION ---

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Invalid entity type: '{$value}'. Must be one of: " . implode(', ', self::ALL)
            );
        }

        $this->value = $value;
    }

    public static function from(string $value): self
    {
        return new self($value);
    }

    public static function tryFrom(string $value): ?self
    {
        if (!in_array($value, self::ALL, true)) {
            return null;
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isSpatial(): bool
    {
        return in_array($this->value, self::SPATIAL_TYPES, true);
    }

    public function isTemporal(): bool
    {
        return in_array($this->value, self::TEMPORAL_TYPES, true);
    }

    public function isEvent(): bool
    {
        return in_array($this->value, self::EVENT_TYPES, true);
    }

    public function isFaction(): bool
    {
        return in_array($this->value, self::FACTION_TYPES, true);
    }

    public function isPowered(): bool
    {
        return in_array($this->value, self::POWERED_TYPES, true);
    }

    public function isConstructedIntelligence(): bool
    {
        return $this->value === self::CONSTRUCTED_INTEL;
    }

    public function category(): string
    {
        foreach (self::CATEGORIES as $category => $types) {
            if (in_array($this->value, $types, true)) {
                return $category;
            }
        }

        return 'unknown';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}