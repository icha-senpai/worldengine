<?php

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Entity;

class EntityStatusChanged
{
    public function __construct(
        public readonly Entity $entity,
        public readonly ?string $previousStatus,
        public readonly string $newStatus
    ) {}

    // Whether this status change is significant enough to
    // warrant an automatic canon state snapshot
    public function isSignificant(): bool
    {
        $significantTransitions = [
            // Alive to any death state
            'alive'      => ['dead', 'undead', 'transformed', 'transcended'],
            // Undead transitions
            'undead'     => ['dead', 'transformed', 'transcended'],
            // Transformation
            'active'     => ['transformed', 'transcended', 'archived'],
            'concept'    => ['active'],
        ];

        return isset($significantTransitions[$this->previousStatus])
            && in_array($this->newStatus, $significantTransitions[$this->previousStatus], true);
    }
}
