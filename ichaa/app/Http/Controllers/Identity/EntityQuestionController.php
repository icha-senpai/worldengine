<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\Request;
use Inertia\Response;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RendersEntityShowPage;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Models\EntityQuestion;
use App\Support\Validation\DataverseRules;

class EntityQuestionController extends Controller
{
    use RendersEntityShowPage;

    public function create(Entity $entity): Response
    {
        return $this->showEntityPage($entity, [
            'questionCreateDrawer' => true,
        ]);
    }

    public function edit(Entity $entity, EntityQuestion $question): Response
    {
        abort_unless((int) $question->entity_id === (int) $entity->id, 404);

        return $this->showEntityPage($entity, [
            'questionEditDrawer' => [
                'question' => $question,
            ],
        ]);
    }

    // POST /entities/{entity}/questions
    public function store(Request $request, Entity $entity): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate(
            collect(DataverseRules::web('entity-questions', 'store'))
                ->except('entity_id')
                ->all()
        );

        $validated['sort_order'] = $validated['sort_order']
            ?? (($entity->questions()->max('sort_order') ?? -1) + 1);

        $entity->questions()->create($validated);

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'questions',
        ], 'Question added.');
    }

    // PUT /entities/{entity}/questions/{question}
    public function update(Request $request, Entity $entity, EntityQuestion $question): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $question->entity_id === (int) $entity->id, 404);

        $validated = $request->validate(DataverseRules::web('entity-questions', 'update'));

        // If being marked resolved, set resolved_at
        if (isset($validated['status']) && $validated['status'] === 'resolved') {
            $validated['resolved_at'] = now();
        }

        $question->update($validated);

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'questions',
        ], 'Question updated.');
    }

    // DELETE /entities/{entity}/questions/{question}
    public function destroy(Entity $entity, EntityQuestion $question): \Illuminate\Http\RedirectResponse
    {
        abort_unless((int) $question->entity_id === (int) $entity->id, 404);

        $question->delete();

        return $this->to('entities.show', [
            'entity' => $entity,
            'tab' => 'questions',
        ], 'Question removed.');
    }
}
