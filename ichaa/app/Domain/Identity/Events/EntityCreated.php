<?php

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Entity;

class EntityCreated
{
    public function __construct(
        public readonly Entity $entity
    ) {}
}
