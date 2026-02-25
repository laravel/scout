<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Bookmark;

/**
 * @template TModel of \Workbench\App\Models\Bookmark
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<TModel>
 */
class BookmarkFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected $model = Bookmark::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chirp_id' => ChirpFactory::new(),
            'label' => fake()->word(),
        ];
    }
}
