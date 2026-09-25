<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;

/**
 * The single point every product-creation path funnels through, once
 * its data is structured and validated (App\Modules\Catalog\Support\
 * ProductRules). Manual (ProductController), Excel import
 * (ProductImportService) and the future voice pipeline
 * (VoiceProductParser) all call create()/update() here instead of each
 * carrying its own persistence logic — see docs/catalog.md
 * §"Contrat commun de création".
 */
class ProductService
{
    /** @param  array<string, mixed>  $data  already validated against ProductRules::rules() */
    public function create(array $data): Product
    {
        return Product::create($data);
    }

    /** @param  array<string, mixed>  $data  already validated against ProductRules::rules(ignore: $product, partial: true) */
    public function update(Product $product, array $data): Product
    {
        $product->update($data);

        return $product;
    }
}
