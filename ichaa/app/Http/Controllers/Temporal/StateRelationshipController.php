<?php

namespace App\Http\Controllers\Temporal;

use App\Domain\Temporal\Models\StateRelationship;
use App\Http\Controllers\ManagedResourceController;

class StateRelationshipController extends ManagedResourceController
{
    protected string $resource = 'state-relationships';

    protected string $modelClass = StateRelationship::class;
}
