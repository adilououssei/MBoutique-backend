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
            'store_id' => Store::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 0, 200),
            'minimum_quantity' => null,
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn () => [
            'quantity' => 5,
            'minimum_quantity' => 10,
        ]);
    }
}
