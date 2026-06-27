<?php

namespace App\Http\Controllers\World;

use App\Domain\World\Models\GalacticRegion;
use App\Http\Controllers\ManagedResourceController;

class GalacticRegionController extends ManagedResourceController
{
    protected string $resource = 'galactic-regions';

    protected string $modelClass = GalacticRegion::class;
}
