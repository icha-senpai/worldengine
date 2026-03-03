<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityAlias;

class EntityAliasController extends Controller
{
    // POST /entities/{entity}/aliases
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'alias'                  => ['required', 'string', 'max:255'],
            'alias_type'             => ['required', 'string'],
            'context'                => ['nullable', 'string'],
            'era_start'              => ['nullable', 'string'],
            'era_end'                => ['nullable', 'string'],
            'is_active'              => ['boolean'],
            'known_by_entity_ids'    => ['nullable', 'array'],
            'visibility'             => ['nullable', 'string'],
            'content_classification' => ['nullable', 'string'],
        ]);

        $entity->aliases()->create($validated);

        return $this->back('Alias added.');
    }

    // PUT /entities/{entity}/aliases/{alias}
    public function update(Request $request, Entity $entity, EntityAlias $alias): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'alias'               => ['sometimes', 'string', 'max:255'],
            'alias_type'          => ['sometimes', 'string'],
            'context'             => ['nullable', 'string'],
            'era_start'           => ['nullable', 'string'],
            'era_end'             => ['nullable', 'string'],
            'is_active'           => ['boolean'],
            'known_by_entity_ids' => ['nullable', 'array'],
        ]);

        $alias->update($validated);

        return $this->back('Alias updated.');
    }

    // DELETE /entities/{entity}/aliases/{alias}
    public function destroy(Entity $entity, EntityAlias $alias): \Illuminate\Http\RedirectResponse
    {
        $alias->delete();

        return $this->back('Alias removed.');
    }
}
