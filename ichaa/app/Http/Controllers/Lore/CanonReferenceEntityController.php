<?php

namespace App\Http\Controllers\Lore;

use App\Domain\Lore\Models\CanonReferenceEntity;
use App\Http\Controllers\ManagedResourceController;

class CanonReferenceEntityController extends ManagedResourceController
{
    protected string $resource = 'canon-reference-entities';

    protected string $modelClass = CanonReferenceEntity::class;
}
