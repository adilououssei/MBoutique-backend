<?php

namespace Database\Factories\Modules\Sales;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 10);
        $unitPrice = fake()->randomFloat(2, 100, 5000);

        return [
            'store_id' => Store::factory(),
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(2, true),
            'pricing_mode' => PricingMode::Retail,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'total_amount' => bcmul((string) $unitPrice, (string) $quantity, 2),
        ];
    }
}
