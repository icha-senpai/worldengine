<?php

namespace App\Domain\Bitcraft\Services;

class BitjitaRequestBudget extends BitcraftApiRequestBudget
{
    public function __construct()
    {
        parent::__construct('bitjita');
    }
}
