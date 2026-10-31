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
        $supplements = $data['supplement_ids'] ?? null;
        unset($data['supplement_ids']);
        $product = Product::create($data);
        if ($supplements !== null) {
            $product->syncSupplements($supplements);
        }

        return $product;
    }

    /** @param  array<string, mixed>  $data  already validated against ProductRules::rules(ignore: $product, partial: true) */
    public function update(Product $product, array $data): Product
    {
        $supplements = array_key_exists('supplement_ids', $data) ? ($data['supplement_ids'] ?? []) : null;
        unset($data['supplement_ids']);
        $product->update($data);
        if ($supplements !== null) {
            $product->syncSupplements($supplements);
        }
        // Vêtements : les noms des variantes suivent l'article (ou leur taille / couleur).
        app(VariantService::class)->syncNames($product);

        return $product;
    }
}
