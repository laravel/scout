<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Chirp;

/**
 * @template TModel of \Workbench\App\Models\Chirp
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<TModel>
 */
class ChirpFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected $model = Chirp::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scout_id' => fake()->uuid(),
            'content' => fake()->realText(),
        ];
    }
}
