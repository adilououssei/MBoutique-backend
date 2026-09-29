<?php

namespace Database\Factories\Modules\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreUser>
 */
class StoreUserFactory extends Factory
{
    protected $model = StoreUser::class;

    public function definition(): array
    {
        return [
            'boutique_id' => Store::factory(),
            'utilisateur_id' => User::factory(),
            'statut' => 'actif',
            'rejoint_le' => now(),
        ];
    }
}
