<?php

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\PowerTier;

class PowerTierChanged
{
    public function __construct(
        public readonly Entity $entity,
        public readonly string $axis,          // ceiling, operating, influence
        public readonly ?string $previousTier,
        public readonly string $newTier
    ) {}

    // Whether this change crossed a meaningful threshold
    // Minor operating fluctuations don't need a canon state snapshot
    // Ceiling changes always do — ceiling is a fundamental identity property
    public function isCeilingChange(): bool
    {
        return $this->axis === PowerTier::AXIS_CEILING;
    }

    public function isOperatingChange(): bool
    {
        return $this->axis === PowerTier::AXIS_OPERATING;
    }

    // Whether the new tier is higher than the previous
    public function isIncrease(): bool
    {
        if (!$this->previousTier) {
            return true;
        }

        $weights = $this->axis === PowerTier::AXIS_INFLUENCE
            ? PowerTier::INFLUENCE_WEIGHTS
            : PowerTier::CEILING_WEIGHTS;

        return ($weights[$this->newTier] ?? 0) > ($weights[$this->previousTier] ?? 0);
    }

    public function isDecrease(): bool
    {
        if (!$this->previousTier) {
            return false;
        }

        return !$this->isIncrease();
    }

    // Seraphine's transformation: continental → cosmic is a major jump
    // Regional → national is a minor shift
    // Threshold is crossing the planetary boundary
    public function crossedPlanetaryThreshold(): bool
    {
        $weights = PowerTier::CEILING_WEIGHTS;

        $previousWeight = $weights[$this->previousTier] ?? 0;
        $newWeight      = $weights[$this->newTier] ?? 0;
        $planetary      = $weights[PowerTier::PLANETARY] ?? 5;

        return $previousWeight < $planetary && $newWeight >= $planetary;
    }
}
