<?php

namespace Database\Factories\Modules\Subscriptions;

use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'price_monthly' => 0,
            'price_yearly' => null,
            'max_stores' => 1,
            'max_users_per_store' => 3,
            'max_products_per_store' => 100,
            'is_active' => true,
        ];
    }

    public function unlimitedStores(): static
    {
        return $this->state(['max_stores' => null]);
    }
}
