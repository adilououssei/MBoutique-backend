<?php

namespace Database\Factories\Modules\CashRegister;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegister>
 */
class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => 'Caisse '.fake()->unique()->numberBetween(1, 9999),
            'code' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
