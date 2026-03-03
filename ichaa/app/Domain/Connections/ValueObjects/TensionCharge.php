<?php

namespace App\Domain\Connections\ValueObjects;

use InvalidArgumentException;

final class TensionCharge
{
    const POSITIVE  = 'positive';
    const NEUTRAL   = 'neutral';
    const NEGATIVE  = 'negative';
    const COMPLEX   = 'complex';
    const VOLATILE  = 'volatile';

    const ALL = [
        self::POSITIVE,
        self::NEUTRAL,
        self::NEGATIVE,
        self::COMPLEX,
        self::VOLATILE,
    ];

    // Charges that signal active narrative pressure
    const ACTIVE_PRESSURE = [
        self::NEGATIVE,
        self::COMPLEX,
        self::VOLATILE,
    ];

    // Numeric instability weights
    // Higher = more likely to change or explode
    const INSTABILITY_WEIGHTS = [
        self::POSITIVE  => 1,
        self::NEUTRAL   => 2,
        self::NEGATIVE  => 3,
        self::COMPLEX   => 4,
        self::VOLATILE  => 5,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Invalid tension charge: '{$value}'. Must be one of: " . implode(', ', self::ALL)
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

    public function isVolatile(): bool
    {
        return $this->value === self::VOLATILE;
    }

    public function isUnderPressure(): bool
    {
        return in_array($this->value, self::ACTIVE_PRESSURE, true);
    }

    public function isMoreUnstableThan(self $other): bool
    {
        return $this->instabilityWeight() > $other->instabilityWeight();
    }

    private function instabilityWeight(): int
    {
        return self::INSTABILITY_WEIGHTS[$this->value] ?? 0;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
