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
            'store_id' => Store::factory(),
            'category_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->optional()->sentence(),
            'sku' => null,
            'barcode' => null,
            'unit' => ProductUnit::Piece->value,
            'purchase_price' => fake()->randomFloat(2, 100, 1000),
            'retail_enabled' => true,
            'retail_price' => fake()->randomFloat(2, 1000, 5000),
            'wholesale_enabled' => false,
            'wholesale_price' => null,
            'is_active' => true,
        ];
    }

    public function wholesaleOnly(): static
    {
        return $this->state(fn () => [
            'retail_enabled' => false,
            'retail_price' => null,
            'wholesale_enabled' => true,
            'wholesale_price' => fake()->randomFloat(2, 800, 4000),
        ]);
    }

    public function retailAndWholesale(): static
    {
        return $this->state(fn () => [
            'retail_enabled' => true,
            'retail_price' => fake()->randomFloat(2, 1000, 5000),
            'wholesale_enabled' => true,
            'wholesale_price' => fake()->randomFloat(2, 800, 4000),
        ]);
    }
}
