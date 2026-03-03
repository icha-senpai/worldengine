<?php

namespace App\Domain\Connections\ValueObjects;

use InvalidArgumentException;

final class RelationshipType
{
    const FAMILIAL       = 'familial';
    const ROMANTIC       = 'romantic';
    const POWER          = 'power';
    const CONFLICT       = 'conflict';
    const ORGANIZATIONAL = 'organizational';
    const KNOWLEDGE      = 'knowledge';
    const POSSESSION     = 'possession';
    const CROSSOVER      = 'crossover';
    const NARRATIVE      = 'narrative';

    const ALL = [
        self::FAMILIAL,
        self::ROMANTIC,
        self::POWER,
        self::CONFLICT,
        self::ORGANIZATIONAL,
        self::KNOWLEDGE,
        self::POSSESSION,
        self::CROSSOVER,
        self::NARRATIVE,
    ];

    // Types that imply a power differential
    const HIERARCHICAL = [
        self::POWER,
        self::ORGANIZATIONAL,
    ];

    // Types that are inherently bidirectional
    const INHERENTLY_MUTUAL = [
        self::FAMILIAL,
        self::ROMANTIC,
        self::CONFLICT,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Invalid relationship type: '{$value}'. Must be one of: " . implode(', ', self::ALL)
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

    public function isHierarchical(): bool
    {
        return in_array($this->value, self::HIERARCHICAL, true);
    }

    public function isInherentlyMutual(): bool
    {
        return in_array($this->value, self::INHERENTLY_MUTUAL, true);
    }

    public function isCrossover(): bool
    {
        return $this->value === self::CROSSOVER;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
