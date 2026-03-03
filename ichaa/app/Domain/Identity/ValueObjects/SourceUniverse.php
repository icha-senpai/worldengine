<?php

namespace App\Domain\Identity\ValueObjects;

use InvalidArgumentException;

final class SourceUniverse
{
    // --- UNIVERSES ---

    const HARRY_POTTER        = 'Harry Potter';
    const COSMERE             = 'Cosmere';
    const WARHAMMER_40K       = 'Warhammer 40K';
    const DUNE                = 'Dune';
    const WHEEL_OF_TIME       = 'Wheel of Time';
    const LORD_OF_THE_RINGS   = 'Lord of the Rings';
    const STAR_WARS           = 'Star Wars';
    const MARVEL               = 'Marvel';
    const DC                  = 'DC';
    const WITCHER             = 'Witcher';
    const ELDER_SCROLLS       = 'Elder Scrolls';
    const FINAL_FANTASY       = 'Final Fantasy';
    const MASS_EFFECT         = 'Mass Effect';
    const DRAGON_AGE          = 'Dragon Age';
    const MISTBORN            = 'Mistborn';
    const STORMLIGHT          = 'Stormlight Archive';
    const FIRST_LAW           = 'First Law';
    const MALAZAN             = 'Malazan';
    const KINGKILLER          = 'Kingkiller Chronicle';
    const NIGHT_CIRCUS        = 'Night Circus';
    const ORIGINAL            = 'Original';

    const ALL = [
        self::HARRY_POTTER,
        self::COSMERE,
        self::WARHAMMER_40K,
        self::DUNE,
        self::WHEEL_OF_TIME,
        self::LORD_OF_THE_RINGS,
        self::STAR_WARS,
        self::MARVEL,
        self::DC,
        self::WITCHER,
        self::ELDER_SCROLLS,
        self::FINAL_FANTASY,
        self::MASS_EFFECT,
        self::DRAGON_AGE,
        self::MISTBORN,
        self::STORMLIGHT,
        self::FIRST_LAW,
        self::MALAZAN,
        self::KINGKILLER,
        self::NIGHT_CIRCUS,
        self::ORIGINAL,
    ];

    // Cosmere sub-universes that are separate series
    // but share the same cosmological framework
    const COSMERE_SERIES = [
        self::MISTBORN,
        self::STORMLIGHT,
        self::COSMERE,
    ];

    // Universes with explicit crossover entry point records
    // Update this as you build crossover_entry_points records
    const HAS_ENTRY_POINTS = [
        self::HARRY_POTTER,
        self::COSMERE,
        self::WARHAMMER_40K,
    ];

    // Primary universes — the ones your AU most heavily draws from
    // Drives display priority in UI
    const PRIMARY = [
        self::HARRY_POTTER,
        self::COSMERE,
        self::WARHAMMER_40K,
        self::DUNE,
    ];

    // --- VALUE OBJECT IMPLEMENTATION ---

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Invalid source universe: '{$value}'. Must be one of: " . implode(', ', self::ALL)
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

    // Validate an array of universe strings
    // Used for the source_universes JSONB array on entities
    public static function fromArray(array $values): array
    {
        return array_map(
            fn(string $v) => new self($v),
            $values
        );
    }

    public static function tryFromArray(array $values): array
    {
        return array_filter(
            array_map(
                fn(string $v) => self::tryFrom($v),
                $values
            )
        );
    }

    // --- ACCESSORS ---

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    // --- SEMANTIC HELPERS ---

    public function isOriginal(): bool
    {
        return $this->value === self::ORIGINAL;
    }

    public function isCosmere(): bool
    {
        return in_array($this->value, self::COSMERE_SERIES, true);
    }

    public function isPrimary(): bool
    {
        return in_array($this->value, self::PRIMARY, true);
    }

    public function hasEntryPoint(): bool
    {
        return in_array($this->value, self::HAS_ENTRY_POINTS, true);
    }

    // Whether this universe is native to your AU
    // Only ORIGINAL entities are truly native
    public function isNative(): bool
    {
        return $this->value === self::ORIGINAL;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}