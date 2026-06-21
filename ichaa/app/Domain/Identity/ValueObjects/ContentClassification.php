<?php

namespace App\Domain\Identity\ValueObjects;

use InvalidArgumentException;

final class ContentClassification
{
    const PUBLIC      = 'public';
    const RESTRICTED  = 'restricted';
    const SECRET      = 'secret';
    const AUTHOR_ONLY = 'author_only';

    const ALL = [
        self::PUBLIC,
        self::RESTRICTED,
        self::SECRET,
        self::AUTHOR_ONLY,
    ];

    // Numeric weights for comparison
    // Higher = more sensitive
    const WEIGHTS = [
        self::PUBLIC      => 1,
        self::RESTRICTED  => 2,
        self::SECRET      => 3,
        self::AUTHOR_ONLY => 4,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Invalid content classification: '{$value}'. Must be one of: " . implode(', ', self::ALL)
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

    public static function default(): self
    {
        return new self(self::RESTRICTED);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isMoreSensitiveThan(self $other): bool
    {
        return $this->weight() > $other->weight();
    }

    public function isAtLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    public function isPublic(): bool
    {
        return $this->value === self::PUBLIC;
    }

    public function isAuthorOnly(): bool
    {
        return $this->value === self::AUTHOR_ONLY;
    }

    public function isSecret(): bool
    {
        return $this->value === self::SECRET;
    }

    private function weight(): int
    {
        return self::WEIGHTS[$this->value] ?? 0;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}