<?php

namespace Database\Factories\Modules\Customers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /** Default: an individual customer, no company. */
    public function definition(): array
    {
        return [
            'boutique_id' => Store::factory(),
            'nom' => fake()->name(),
            'telephone' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'nom_entreprise' => null,
            'adresse' => fake()->optional()->address(),
            'notes' => fake()->optional()->sentence(),
            'actif' => true,
        ];
    }

    public function professional(): static
    {
        return $this->state(fn () => [
            'nom_entreprise' => fake()->company(),
        ]);
    }

    public function withoutPhone(): static
    {
        return $this->state(fn () => ['telephone' => null]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }
}
