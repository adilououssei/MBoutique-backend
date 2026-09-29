<?php

namespace Database\Factories\Modules\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\BusinessUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessUser>
 */
class BusinessUserFactory extends Factory
{
    protected $model = BusinessUser::class;

    public function definition(): array
    {
        return [
            'entreprise_id' => Business::factory(),
            'utilisateur_id' => User::factory(),
            'role' => 'proprietaire',
        ];
    }
}
