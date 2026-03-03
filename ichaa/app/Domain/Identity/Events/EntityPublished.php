<?php

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Entity;

class EntityPublished
{
    public function __construct(
        public readonly Entity $entity
    ) {}
}
