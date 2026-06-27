<?php

namespace App\Http\Controllers\System;

use App\Domain\System\Models\NotionSyncMapping;
use App\Http\Controllers\ManagedResourceController;

class NotionSyncMappingController extends ManagedResourceController
{
    protected string $resource = 'notion-sync-mappings';

    protected string $modelClass = NotionSyncMapping::class;
}
