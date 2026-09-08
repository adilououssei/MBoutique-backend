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
            'selling_price' => fake()->randomFloat(2, 1000, 5000),
            'is_active' => true,
        ];
    }
}
