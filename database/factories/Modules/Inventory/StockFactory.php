<?php

namespace Database\Factories\Modules\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'boutique_id' => Store::factory(),
            'produit_id' => Product::factory(),
            'quantite' => fake()->randomFloat(3, 0, 200),
            'quantite_minimum' => null,
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn () => [
            'quantite' => 5,
            'quantite_minimum' => 10,
        ]);
    }
}
