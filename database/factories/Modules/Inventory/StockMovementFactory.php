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
            'boutique_id' => Store::factory(),
            'stock_id' => Stock::factory(),
            'produit_id' => Product::factory(),
            'type' => StockMovementType::Purchase,
            'quantite' => $quantity,
            'quantite_avant' => 0,
            'quantite_apres' => $quantity,
            'motif' => null,
            'cree_par_id' => null,
        ];
    }
}
