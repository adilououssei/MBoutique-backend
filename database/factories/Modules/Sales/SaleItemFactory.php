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
            'boutique_id' => Store::factory(),
            'vente_id' => Sale::factory(),
            'produit_id' => Product::factory(),
            'nom_produit' => fake()->words(2, true),
            'mode_prix' => PricingMode::Retail,
            'prix_unitaire' => $unitPrice,
            'quantite' => $quantity,
            'montant_total' => bcmul((string) $unitPrice, (string) $quantity, 2),
        ];
    }
}
