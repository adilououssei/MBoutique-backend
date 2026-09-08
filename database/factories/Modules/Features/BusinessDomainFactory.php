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
            'name' => $name,
            'slug' => Str::slug($name, '_'),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
