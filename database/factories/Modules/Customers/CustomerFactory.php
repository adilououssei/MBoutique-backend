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
            'store_id' => Store::factory(),
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'company_name' => null,
            'address' => fake()->optional()->address(),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function professional(): static
    {
        return $this->state(fn () => [
            'company_name' => fake()->company(),
        ]);
    }

    public function withoutPhone(): static
    {
        return $this->state(fn () => ['phone' => null]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }
}
