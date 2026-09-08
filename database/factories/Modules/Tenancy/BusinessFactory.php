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
            'owner_user_id' => User::factory(),
            'name' => fake()->company(),
            'legal_name' => null,
            'country' => 'CI',
            'currency' => 'XOF',
            'timezone' => 'Africa/Abidjan',
            'status' => 'active',
        ];
    }
}
