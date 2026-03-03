<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityNote;

class EntityNoteController extends Controller
{
    // POST /entities/{entity}/notes
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'note_label' => ['nullable', 'string', 'max:255'],
            'content'    => ['required', 'array'],  // Tiptap JSON
            'sort_order' => ['nullable', 'integer'],
        ]);

        $entity->notes()->create($validated);

        return $this->back('Note added.');
    }

    // PUT /entities/{entity}/notes/{note}
    public function update(Request $request, Entity $entity, EntityNote $note): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'note_label' => ['nullable', 'string', 'max:255'],
            'content'    => ['sometimes', 'array'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $note->update($validated);

        return $this->back('Note updated.');
    }

    // DELETE /entities/{entity}/notes/{note}
    public function destroy(Entity $entity, EntityNote $note): \Illuminate\Http\RedirectResponse
    {
        $note->delete();

        return $this->back('Note deleted.');
    }
}
