<?php

namespace App\Domain\Bitcraft\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class BitjitaRefreshDelayed extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Bitjita refresh delayed. Try again shortly; previously fetched data remains available.');
    }
}
