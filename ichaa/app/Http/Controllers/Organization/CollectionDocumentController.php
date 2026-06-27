<?php

namespace App\Http\Controllers\Organization;

use App\Domain\Organization\Models\CollectionDocument;
use App\Http\Controllers\ManagedResourceController;

class CollectionDocumentController extends ManagedResourceController
{
    protected string $resource = 'collection-documents';

    protected string $modelClass = CollectionDocument::class;
}
