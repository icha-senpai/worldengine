<?php

namespace App\Http\Controllers\Lore;

use App\Domain\Lore\Models\DocumentEntity;
use App\Http\Controllers\ManagedResourceController;

class DocumentEntityController extends ManagedResourceController
{
    protected string $resource = 'document-entities';

    protected string $modelClass = DocumentEntity::class;
}
