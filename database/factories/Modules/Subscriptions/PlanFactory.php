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
            'nom' => fake()->words(2, true),
            'prix_mensuel' => 0,
            'prix_annuel' => null,
            'max_boutiques' => 1,
            'max_utilisateurs_par_boutique' => 3,
            'max_produits_par_boutique' => 100,
            'actif' => true,
        ];
    }

    public function unlimitedStores(): static
    {
        return $this->state(['max_boutiques' => null]);
    }
}
