<?php

namespace App\Domain\Identity\ValueObjects;

use InvalidArgumentException;

final class VisibilityLevel
{
    const PRIVATE       = 'private';
    const AUTHOR_ONLY   = 'author_only';
    const SECRET        = 'secret';
    const PUBLIC_KNOWLEDGE = 'public_knowledge';

    const ALL = [
        self::PRIVATE,
        self::AUTHOR_ONLY,
        self::SECRET,
        self::PUBLIC_KNOWLEDGE,
    ];

    // Visibility levels that are never exposed on a public-facing site
    const NON_PUBLIC = [
        self::PRIVATE,
        self::AUTHOR_ONLY,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(
                "Invalid visibility level: '{$value}'. Must be one of: " . implode(', ', self::ALL)
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
        return new self(self::PRIVATE);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isPublic(): bool
    {
        return $this->value === self::PUBLIC_KNOWLEDGE;
    }

    public function isPrivate(): bool
    {
        return $this->value === self::PRIVATE;
    }

    public function isAuthorOnly(): bool
    {
        return $this->value === self::AUTHOR_ONLY;
    }

    public function isNonPublic(): bool
    {
        return in_array($this->value, self::NON_PUBLIC, true);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}