<?php

namespace App\Exceptions;

use RuntimeException;

class StaleRevisionException extends RuntimeException
{
    public function __construct(
        private readonly int $expectedRevisionId,
        private readonly int $providedRevisionId,
    ) {
        parent::__construct("Stale revision. Expected revision [{$expectedRevisionId}] but received [{$providedRevisionId}].");
    }

    public function expectedRevisionId(): int
    {
        return $this->expectedRevisionId;
    }

    public function providedRevisionId(): int
    {
        return $this->providedRevisionId;
    }
}
