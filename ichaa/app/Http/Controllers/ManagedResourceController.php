<?php

namespace App\Http\Controllers;

use App\Support\Validation\DataverseRules;
use App\Support\Web\ResourcePageBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

abstract class ManagedResourceController extends Controller
{
    public function __construct(
        protected readonly ResourcePageBuilder $pages,
    ) {}

    protected string $resource;

    protected string $modelClass;

    public function index(Request $request): Response
    {
        return $this->page('ScaffoldResources/Index', $this->pages->indexProps($this->resource, $request));
    }

    public function create(): Response
    {
        abort_if($this->pages->readOnly($this->resource), 404);

        return $this->page('ScaffoldResources/Index', $this->pages->indexProps($this->resource, request(), [
            'createDrawer' => $this->pages->createDrawerProps($this->resource),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($this->pages->readOnly($this->resource), 404);

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->modelClass;
        $record = $modelClass::query()->create(
            $this->pages->normalizePayload($request->validate(DataverseRules::web($this->resource, 'store')))
        );

        return $this->to("{$this->resource}.show", [$record], "{$this->resourceLabel()} created.");
    }

    public function show(int|string $record): Response
    {
        return $this->page('ScaffoldResources/Show', $this->pages->showProps($this->resource, $this->findRecord($record)));
    }

    public function edit(int|string $record): Response
    {
        abort_if($this->pages->readOnly($this->resource), 404);

        $model = $this->findRecord($record);

        return $this->page('ScaffoldResources/Show', $this->pages->showProps($this->resource, $model, [
            'editDrawer' => $this->pages->editDrawerProps($this->resource, $model),
        ]));
    }

    public function update(Request $request, int|string $record): RedirectResponse
    {
        abort_if($this->pages->readOnly($this->resource), 404);

        $model = $this->findRecord($record);
        $model->fill(
            $this->pages->normalizePayload($request->validate(DataverseRules::web($this->resource, 'update')))
        );
        $model->save();

        return $this->to("{$this->resource}.show", [$model], "{$this->resourceLabel()} updated.");
    }

    public function destroy(int|string $record): RedirectResponse
    {
        abort_if($this->pages->readOnly($this->resource), 404);

        $model = $this->findRecord($record);
        $model->delete();

        return $this->to("{$this->resource}.index", [], "{$this->resourceLabel()} deleted.");
    }

    protected function findRecord(int|string $record): Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->modelClass;

        return $modelClass::query()->findOrFail($record);
    }

    protected function resourceLabel(): string
    {
        return str($this->resource)
            ->replace('-', ' ')
            ->singular()
            ->title()
            ->toString();
    }
}
