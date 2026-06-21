<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;
use App\Http\Controllers\Controller;
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
        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:255'],
            'alias_type' => ['required', 'string'],
            'context' => ['nullable', 'string'],
            'era_start' => ['nullable', 'string'],
            'era_end' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'known_by_entity_ids' => ['nullable', 'array'],
            'visibility' => ['nullable', 'string'],
            'content_classification' => ['nullable', 'string'],
        ]);

        $validated = array_filter($validated, fn ($v) => ! ($v === '' || $v === null) || is_array($v) || is_bool($v));

        $entity->aliases()->create($validated);

        return $this->back('Alias added.');
    }

    // PUT /entities/{entity}/aliases/{alias}
    public function update(Request $request, Entity $entity, EntityAlias $alias): RedirectResponse
    {
        abort_unless((int) $alias->entity_id === (int) $entity->id, 404);

        $validated = $request->validate([
            'alias' => ['sometimes', 'string', 'max:255'],
            'alias_type' => ['sometimes', 'string'],
            'context' => ['nullable', 'string'],
            'era_start' => ['nullable', 'string'],
            'era_end' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'known_by_entity_ids' => ['nullable', 'array'],
        ]);

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
