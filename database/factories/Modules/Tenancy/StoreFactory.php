<?php

namespace Database\Factories\Modules\Tenancy;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'entreprise_id' => Business::factory(),
            'domaine_activite_id' => BusinessDomain::factory(),
            'nom' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'adresse' => fake()->address(),
            'telephone' => fake()->phoneNumber(),
            'devise' => 'XOF',
            'fuseau_horaire' => 'Africa/Abidjan',
            'statut' => 'active',
            'parametres' => [],
        ];
    }
}
