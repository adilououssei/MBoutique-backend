<?php

namespace Database\Factories\Modules\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'proprietaire_id' => User::factory(),
            'nom' => fake()->company(),
            'raison_sociale' => null,
            'pays' => 'CI',
            'devise' => 'XOF',
            'fuseau_horaire' => 'Africa/Abidjan',
            'statut' => 'active',
        ];
    }
}
