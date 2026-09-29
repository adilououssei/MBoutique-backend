<?php

namespace Database\Factories\Modules\Features;

use App\Modules\Features\Models\BusinessDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessDomain>
 */
class BusinessDomainFactory extends Factory
{
    protected $model = BusinessDomain::class;

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
