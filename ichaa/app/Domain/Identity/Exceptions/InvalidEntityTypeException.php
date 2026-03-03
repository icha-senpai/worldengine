<?php

namespace App\Domain\Identity\Exceptions;

use InvalidArgumentException;

class InvalidEntityTypeException extends InvalidArgumentException
{
    public function __construct(string $attempted)
    {
        parent::__construct(
            "'{$attempted}' is not a valid entity type. Check EntityType::ALL for valid values."
        );
    }
}
