<?php

namespace Database\Factories\Modules\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 50);

        return [
            'store_id' => Store::factory(),
            'stock_id' => Stock::factory(),
            'product_id' => Product::factory(),
            'type' => StockMovementType::Purchase,
            'quantity' => $quantity,
            'quantity_before' => 0,
            'quantity_after' => $quantity,
            'reason' => null,
            'created_by_user_id' => null,
        ];
    }
}
