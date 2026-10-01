<?php

namespace Database\Factories;

use App\Domain\Bitcraft\Models\BitcraftGuide;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BitcraftGuide>
 */
class BitcraftGuideFactory extends Factory
{
    protected $model = BitcraftGuide::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'summary' => fake()->sentence(),
            'category' => 'Crafting',
            'content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => fake()->paragraph()]],
                ]],
            ],
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['published_at' => now()]);
    }
}
