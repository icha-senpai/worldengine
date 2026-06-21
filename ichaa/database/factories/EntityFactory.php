<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\ValueObjects\ContentClassification;
use App\Domain\Identity\ValueObjects\EntityType;
use App\Domain\Identity\ValueObjects\VisibilityLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entity>
 */
class EntityFactory extends Factory
{
    protected $model = Entity::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'entity_type' => EntityType::CHARACTER,
            'summary' => fake()->sentence(),
            'source_universes' => ['Harry Potter'],
            'origin_type' => 'native',
            'status' => 'active',
            'visibility' => VisibilityLevel::PRIVATE,
            'content_classification' => ContentClassification::RESTRICTED,
            'attributes' => [],
            'has_attributes' => false,
            'has_relationships' => false,
            'has_timeline_entries' => false,
            'has_documents' => false,
            'has_state_snapshots' => false,
            'has_aliases' => false,
            'has_media' => false,
            'completion_score' => 15,
        ];
    }

    public function character(): static
    {
        return $this->state(fn () => [
            'entity_type' => EntityType::CHARACTER,
        ]);
    }

    public function publishable(): static
    {
        return $this->state(fn () => [
            'summary' => fake()->paragraph(),
            'true_nature' => ['core' => fake()->sentence()],
            'source_universes' => ['Harry Potter'],
            'power_tier_ceiling' => 'planetary',
            'power_tier_operating' => 'continental',
            'has_attributes' => true,
            'has_relationships' => true,
            'has_timeline_entries' => true,
            'has_documents' => true,
            'has_state_snapshots' => true,
            'has_aliases' => true,
            'has_media' => true,
            'completion_score' => 100,
        ]);
    }
}
