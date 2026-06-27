<?php

namespace App\Http\Controllers\Scaffold;

use App\Http\Controllers\Controller;
use App\Support\Web\TopLevelModeledResourceCatalog;
use Illuminate\Http\Request;
use Inertia\Response;

class TopLevelModeledResourceController extends Controller
{
    public function __construct(
        private readonly TopLevelModeledResourceCatalog $catalog,
    ) {}

    public function index(Request $request): Response
    {
        $resource = (string) $request->route('resource');
        $records = $this->catalog->paginate($resource, $request);

        return $this->page('ModeledResources/Index', $this->catalog->indexProps(
            $resource,
            $records,
            $request->only(array_column($this->catalog->definition($resource)['filter_fields'], 'key')),
        ));
    }

    public function show(Request $request, string $record): Response
    {
        $resource = (string) $request->route('resource');
        $model = $this->catalog->findOrFail($resource, $record);

        return $this->page('ModeledResources/Show', $this->catalog->showProps($resource, $model));
    }
}
