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
            'boutique_id' => Store::factory(),
            'nom' => 'Caisse '.fake()->unique()->numberBetween(1, 9999),
            'code' => null,
            'actif' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['actif' => false]);
    }
}
