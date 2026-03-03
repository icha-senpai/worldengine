<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;
use App\Domain\Identity\Models\Entity;

class CannotPublishIncompleteEntityException extends RuntimeException
{
    public function __construct(
        public readonly Entity $entity,
        string $reason
    ) {
        parent::__construct(
            "Cannot publish entity '{$entity->name}' (ID: {$entity->id}): {$reason}"
        );
    }
}
