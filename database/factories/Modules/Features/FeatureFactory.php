<?php

namespace Database\Factories\Modules\Features;

use App\Modules\Features\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'nom' => $name,
            'slug' => Str::slug($name, '_'),
            'description' => fake()->sentence(),
            'actif' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['actif' => false]);
    }
}
