<?php

namespace App\Http\Controllers\System;

use App\Domain\System\Models\NotionNote;
use App\Http\Controllers\ManagedResourceController;

class NotionNoteController extends ManagedResourceController
{
    protected string $resource = 'notion-notes';

    protected string $modelClass = NotionNote::class;
}
