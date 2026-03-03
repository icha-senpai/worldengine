<?php

namespace App\Domain\Identity\Listeners;

use App\Domain\Identity\Events\EntityCreated;
use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\CompletionScoreCalculator;

class UpdateCompletionScore
{
    public function __construct(
        private readonly CompletionScoreCalculator $calculator
    ) {}

    public function handleEntityCreated(EntityCreated $event): void
    {
        $this->recalculate($event->entity);
    }

    // Also called directly by EntityService after any update
    // Not bound to a specific event — called explicitly when needed
    public function recalculate(Entity $entity): void
    {
        $score = $this->calculator->calculate($entity);

        // Update without firing events — prevents infinite loop
        Entity::withoutEvents(function () use ($entity, $score) {
            $entity->update(['completion_score' => $score]);
        });
    }
}
