<?php

namespace Database\Factories\Modules\Catalog;

use App\Modules\Catalog\Enums\ProductUnit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Default: retail only, the common case. Use wholesaleOnly() or
     * retailAndWholesale() for the other two combinations described in
     * docs/catalog.md §5.
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'boutique_id' => Store::factory(),
            'categorie_id' => null,
            'nom' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->optional()->sentence(),
            'sku' => null,
            'code_barres' => null,
            'unite' => ProductUnit::Piece->value,
            'prix_achat' => fake()->randomFloat(2, 100, 1000),
            'vente_detail_active' => true,
            'prix_detail' => fake()->randomFloat(2, 1000, 5000),
            'vente_gros_active' => false,
            'prix_gros' => null,
            'actif' => true,
        ];
    }

    public function wholesaleOnly(): static
    {
        return $this->state(fn () => [
            'vente_detail_active' => false,
            'prix_detail' => null,
            'vente_gros_active' => true,
            'prix_gros' => fake()->randomFloat(2, 800, 4000),
        ]);
    }

    public function retailAndWholesale(): static
    {
        return $this->state(fn () => [
            'vente_detail_active' => true,
            'prix_detail' => fake()->randomFloat(2, 1000, 5000),
            'vente_gros_active' => true,
            'prix_gros' => fake()->randomFloat(2, 800, 4000),
        ]);
    }
}
