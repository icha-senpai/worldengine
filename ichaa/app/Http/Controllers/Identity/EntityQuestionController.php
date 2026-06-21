<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityQuestion;

class EntityQuestionController extends Controller
{
    public function create(Entity $entity): \Illuminate\Http\RedirectResponse
    {
        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'questions',
            'compose' => 1,
        ]);
    }

    public function edit(Entity $entity, EntityQuestion $question): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $question->entity_id === (int) $entity->id, 404);

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'questions',
            'edit_question' => $question->id,
        ]);
    }

    // POST /entities/{entity}/questions
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'question'                    => ['required', 'string'],
            'context'  => ['nullable', 'string'],
            'status'   => ['nullable', 'string'],
            'priority'                    => ['nullable', 'string'],
            'linked_entity_ids'           => ['nullable', 'array'],
            'linked_group_relationship_ids'=> ['nullable', 'array'],
            'sort_order'                  => ['nullable', 'integer'],
        ]);

        $validated['sort_order'] = $validated['sort_order']
            ?? (($entity->questions()->max('sort_order') ?? -1) + 1);

        $entity->questions()->create($validated);

        return $this->back('Question added.');
    }

    // PUT /entities/{entity}/questions/{question}
    public function update(Request $request, Entity $entity, EntityQuestion $question): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $question->entity_id === (int) $entity->id, 404);

        $validated = $request->validate([
            'question'   => ['sometimes', 'string'],
            'context'    => ['nullable', 'string'],
            'status'     => ['nullable', 'string'],
            'priority'   => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
        ]);

        // If being marked resolved, set resolved_at
        if (isset($validated['status']) && $validated['status'] === 'resolved') {
            $validated['resolved_at'] = now();
        }

        $question->update($validated);

        return $this->back('Question updated.');
    }

    // DELETE /entities/{entity}/questions/{question}
    public function destroy(Entity $entity, EntityQuestion $question): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $question->entity_id === (int) $entity->id, 404);

        $question->delete();

        return $this->back('Question removed.');
    }
}
