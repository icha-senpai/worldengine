<?php

namespace Tests\Unit\Identity;

use App\Domain\Identity\Models\Entity;
use App\Domain\Identity\Services\CompletionScoreCalculator;
use App\Domain\Identity\ValueObjects\EntityType;
use Tests\TestCase;

class CompletionScoreCalculatorTest extends TestCase
{
    public function test_it_caps_a_fully_populated_character_at_one_hundred(): void
    {
        $calculator = new CompletionScoreCalculator;
        $entity = new Entity([
            'entity_type' => EntityType::CHARACTER,
            'summary' => 'Built out summary',
            'true_nature' => ['hidden' => 'truth'],
            'source_universes' => ['Harry Potter'],
            'power_tier_ceiling' => 'planetary',
            'power_tier_operating' => 'continental',
            'has_aliases' => true,
            'has_relationships' => true,
            'has_timeline_entries' => true,
            'has_state_snapshots' => true,
            'has_documents' => true,
            'has_media' => true,
            'has_attributes' => true,
        ]);

        $this->assertSame(100, $calculator->calculate($entity));
    }

    public function test_breakdown_reports_earned_and_missing_checks(): void
    {
        $calculator = new CompletionScoreCalculator;
        $entity = new Entity([
            'entity_type' => EntityType::CHARACTER,
            'summary' => 'Only a summary exists',
            'source_universes' => ['Harry Potter'],
            'has_relationships' => true,
        ]);

        $breakdown = $calculator->breakdown($entity);

        $this->assertSame(35, $breakdown['score']);
        $this->assertContains('summary', $breakdown['earned']);
        $this->assertContains('relationships', $breakdown['earned']);
        $this->assertContains('source_universes', $breakdown['earned']);
        $this->assertContains('true_nature', $breakdown['missing']);
        $this->assertContains('power_tier_ceiling', $breakdown['missing']);
        $this->assertContains('power_tier_operating', $breakdown['missing']);
    }
}
