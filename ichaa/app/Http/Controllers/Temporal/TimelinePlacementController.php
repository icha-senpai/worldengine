<?php

namespace App\Http\Controllers\Temporal;

use App\Domain\Temporal\Models\TimelineEntity;
use App\Http\Controllers\ManagedResourceController;

class TimelinePlacementController extends ManagedResourceController
{
    protected string $resource = 'timeline-placements';

    protected string $modelClass = TimelineEntity::class;
}
