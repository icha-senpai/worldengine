<?php

namespace App\Domain\Identity\ValueObjects;

use InvalidArgumentException;

final class PowerTier
{
    // --- TIERS ---
    // Used across all three axes: ceiling, operating, influence

    const STREET_LEVEL   = 'street_level';
    const REGIONAL       = 'regional';
    const NATIONAL       = 'national';
    const CONTINENTAL    = 'continental';
    const PLANETARY      = 'planetary';
    const COSMIC         = 'cosmic';
    const MULTIVERSAL    = 'multiversal';
    const TRANSCENDENT   = 'transcendent';

    // Influence axis has additional lower tiers
    const PERSONAL       = 'personal';
    const LOCAL          = 'local';
    const FACTIONAL      = 'factional';
    const GLOBAL         = 'global';
    const CIVILIZATIONAL = 'civilizational';
    const UNIVERSAL      = 'universal';

    // --- AXIS DEFINITIONS ---
    // Ceiling and operating share the same tier set
    // Influence has its own extended set

    const CEILING_TIERS = [
        self::STREET_LEVEL,
        self::REGIONAL,
        self::NATIONAL,
        self::CONTINENTAL,
        self::PLANETARY,
        self::COSMIC,
        self::MULTIVERSAL,
        self::TRANSCENDENT,
    ];

    const OPERATING_TIERS = self::CEILING_TIERS;

    const INFLUENCE_TIERS = [
        self::PERSONAL,
        self::LOCAL,
        self::FACTIONAL,
        self::REGIONAL,
        self::NATIONAL,
        self::GLOBAL,
        self::CIVILIZATIONAL,
        self::UNIVERSAL,
    ];

    // Numeric weights for comparison
    // Higher number = more powerful
    const CEILING_WEIGHTS = [
        self::STREET_LEVEL   => 1,
        self::REGIONAL       => 2,
        self::NATIONAL       => 3,
        self::CONTINENTAL    => 4,
        self::PLANETARY      => 5,
        self::COSMIC         => 6,
        self::MULTIVERSAL    => 7,
        self::TRANSCENDENT   => 8,
    ];

    const INFLUENCE_WEIGHTS = [
        self::PERSONAL       => 1,
        self::LOCAL          => 2,
        self::FACTIONAL      => 3,
        self::REGIONAL       => 4,
        self::NATIONAL       => 5,
        self::GLOBAL         => 6,
        self::CIVILIZATIONAL => 7,
        self::UNIVERSAL      => 8,
    ];

    // --- AXIS ENUM ---

    const AXIS_CEILING   = 'ceiling';
    const AXIS_OPERATING = 'operating';
    const AXIS_INFLUENCE = 'influence';

    const AXES = [
        self::AXIS_CEILING,
        self::AXIS_OPERATING,
        self::AXIS_INFLUENCE,
    ];

    // --- VALUE OBJECT IMPLEMENTATION ---

    private string $value;
    private string $axis;

    private function __construct(string $value, string $axis)
    {
        if (!in_array($axis, self::AXES, true)) {
            throw new InvalidArgumentException(
                "Invalid power tier axis: '{$axis}'. Must be one of: " . implode(', ', self::AXES)
            );
        }

        $validTiers = match($axis) {
            self::AXIS_CEILING   => self::CEILING_TIERS,
            self::AXIS_OPERATING => self::OPERATING_TIERS,
            self::AXIS_INFLUENCE => self::INFLUENCE_TIERS,
        };

        if (!in_array($value, $validTiers, true)) {
            throw new InvalidArgumentException(
                "Invalid power tier '{$value}' for axis '{$axis}'. Must be one of: "
                . implode(', ', $validTiers)
            );
        }

        $this->value = $value;
        $this->axis  = $axis;
    }

    public static function ceiling(string $value): self
    {
        return new self($value, self::AXIS_CEILING);
    }

    public static function operating(string $value): self
    {
        return new self($value, self::AXIS_OPERATING);
    }

    public static function influence(string $value): self
    {
        return new self($value, self::AXIS_INFLUENCE);
    }

    public static function from(string $value, string $axis): self
    {
        return new self($value, $axis);
    }

    public static function tryFrom(string $value, string $axis): ?self
    {
        try {
            return new self($value, $axis);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // --- ACCESSORS ---

    public function value(): string
    {
        return $this->value;
    }

    public function axis(): string
    {
        return $this->axis;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value
            && $this->axis  === $other->axis;
    }

    // --- COMPARISON ---
    // Returns true if this tier is higher than the given tier
    // Both must be on the same axis

    public function isHigherThan(self $other): bool
    {
        $this->assertSameAxis($other);

        return $this->weight() > $other->weight();
    }

    public function isLowerThan(self $other): bool
    {
        $this->assertSameAxis($other);

        return $this->weight() < $other->weight();
    }

    public function isAtLeast(self $other): bool
    {
        $this->assertSameAxis($other);

        return $this->weight() >= $other->weight();
    }

    // --- SEMANTIC HELPERS ---

    public function isCosmic(): bool
    {
        return $this->value === self::COSMIC;
    }

    public function isTranscendent(): bool
    {
        return $this->value === self::TRANSCENDENT;
    }

    public function isMultiversal(): bool
    {
        return $this->value === self::MULTIVERSAL;
    }

    // Whether operating tier is below ceiling
    // A gap here means the entity has unrealized potential
    // Seraphine pre-transformation: ceiling cosmic, operating continental
    public static function hasUnrealizedPotential(self $ceiling, self $operating): bool
    {
        if ($ceiling->axis !== self::AXIS_CEILING || $operating->axis !== self::AXIS_OPERATING) {
            throw new InvalidArgumentException(
                'hasUnrealizedPotential requires ceiling and operating axes respectively'
            );
        }

        return $ceiling->weight() > $operating->weight();
    }

    // --- PRIVATE ---

    private function weight(): int
    {
        return match($this->axis) {
            self::AXIS_CEILING,
            self::AXIS_OPERATING => self::CEILING_WEIGHTS[$this->value] ?? 0,
            self::AXIS_INFLUENCE => self::INFLUENCE_WEIGHTS[$this->value] ?? 0,
        };
    }

    private function assertSameAxis(self $other): void
    {
        if ($this->axis !== $other->axis) {
            throw new InvalidArgumentException(
                "Cannot compare power tiers across different axes: '{$this->axis}' vs '{$other->axis}'"
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}