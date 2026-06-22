<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Http\Controllers\Controller;
use App\Support\Validation\DataverseRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntityAliasController extends Controller
{
    public function create(Entity $entity): RedirectResponse
    {
        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'aliases',
            'compose' => 1,
        ]);
    }

    public function edit(Entity $entity, EntityAlias $alias): RedirectResponse
    {
        abort_unless((int) $alias->entity_id === (int) $entity->id, 404);

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'aliases',
            'edit_alias' => $alias->id,
        ]);
    }

    // POST /entities/{entity}/aliases
    public function store(Request $request, Entity $entity): RedirectResponse
    {
        $validated = $request->validate(
            collect(DataverseRules::web('entity-aliases', 'store'))
                ->except('entity_id')
                ->all()
        );

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $entity->aliases()->create($validated);

        return $this->back('Alias added.');
    }

    // PUT /entities/{entity}/aliases/{alias}
    public function update(Request $request, Entity $entity, EntityAlias $alias): RedirectResponse
    {
        abort_unless((int) $alias->entity_id === (int) $entity->id, 404);

        $validated = $request->validate(DataverseRules::web('entity-aliases', 'update'));

        $alias->update($validated);

        return $this->back('Alias updated.');
    }

    // DELETE /entities/{entity}/aliases/{alias}
    public function destroy(Entity $entity, EntityAlias $alias): RedirectResponse
    {
        abort_unless((int) $alias->entity_id === (int) $entity->id, 404);

        $alias->delete();

        return $this->back('Alias removed.');
    }
}
