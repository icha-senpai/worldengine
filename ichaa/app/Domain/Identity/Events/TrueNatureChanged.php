<?php

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Entity;

class TrueNatureChanged
{
    public function __construct(
        public readonly Entity $entity,
        public readonly ?array $previousTrueNature,
        public readonly ?array $newTrueNature
    ) {}

    // True nature is a fundamental identity property
    // Any change to it always warrants a canon state snapshot
    // No significance threshold — always significant
    public function alwaysSignificant(): bool
    {
        return true;
    }

    public function wasEmpty(): bool
    {
        return empty($this->previousTrueNature);
    }

    // First time true nature is being set — entity is being defined
    public function isInitialDefinition(): bool
    {
        return $this->wasEmpty() && !empty($this->newTrueNature);
    }
}
