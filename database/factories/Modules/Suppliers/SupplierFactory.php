<?php

namespace Database\Factories\Modules\Suppliers;

use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'boutique_id' => Store::factory(),
            'nom' => fake()->company(),
            'nom_contact' => fake()->optional()->name(),
            'telephone' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'adresse' => fake()->optional()->address(),
            'notes' => null,
            'actif' => true,
        ];
    }
}
