<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use Inertia\Response;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RendersEntityShowPage;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityNote;
use App\Support\Validation\DataverseRules;

class EntityNoteController extends Controller
{
    use RendersEntityShowPage;

    public function create(Entity $entity): Response
    {
        return $this->showEntityPage($entity, [
            'noteCreateDrawer' => true,
        ]);
    }

    public function edit(Entity $entity, EntityNote $note): Response
    {
        abort_unless((int) $note->entity_id === (int) $entity->id, 404);

        return $this->showEntityPage($entity, [
            'noteEditDrawer' => [
                'note' => $note,
            ],
        ]);
    }

    // POST /entities/{entity}/notes
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(
            collect(DataverseRules::web('entity-notes', 'store'))
                ->except('entity_id')
                ->all()
        );

        $validated['sort_order'] = $validated['sort_order']
            ?? (($entity->notes()->max('sort_order') ?? -1) + 1);

        $entity->notes()->create($validated);

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'notes',
        ], 'Note added.');
    }

    // PUT /entities/{entity}/notes/{note}
    public function update(Request $request, Entity $entity, EntityNote $note): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $note->entity_id === (int) $entity->id, 404);

        $validated = $request->validate(DataverseRules::web('entity-notes', 'update'));

        if (($validated['sort_order'] ?? null) === null) {
            unset($validated['sort_order']);
        }

        $note->update($validated);

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'notes',
        ], 'Note updated.');
    }

    // DELETE /entities/{entity}/notes/{note}
    public function destroy(Entity $entity, EntityNote $note): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $note->entity_id === (int) $entity->id, 404);

        $note->delete();

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'notes',
        ], 'Note deleted.');
    }
}
