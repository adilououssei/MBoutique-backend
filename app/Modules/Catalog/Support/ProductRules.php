<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Enums\ProductUnit;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Validation\Rule;

/**
 * The single source of truth for what makes a valid Product payload,
 * shared by every creation path: manual (CreateProductRequest/
 * UpdateProductRequest), Excel import (ProductImportService), and the
 * future voice pipeline (VoiceProductParser output). None of these
 * paths defines its own validation logic — they all call rules() and
 * hand the result to a Validator, then go through ProductService. See
 * docs/catalog.md §"Contrat commun de création".
 */
final class ProductRules
{
    /**
     * @param  mixed  $ignore  the Product (or id) to exclude from the
     *                         uniqueness checks — the product being updated, null on create.
     * @param  bool  $partial  true for an update, where an absent field
     *                         simply means "leave unchanged" instead of "invalid".
     */
    public static function rules(mixed $ignore = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'nom' => [$required, 'string', 'max:255'],
            'slug' => [
                $required, 'string', 'max:255', 'alpha_dash',
                TenantScopedRules::uniqueInCurrentStore('produits', 'slug')->ignore($ignore),
            ],
            'description' => ['nullable', 'string'],
            // category_id must belong to the CURRENT store — Couche 5 of
            // docs/multi-tenancy.md.
            'categorie_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'sku' => [
                'nullable', 'string', 'max:100',
                TenantScopedRules::uniqueInCurrentStore('produits', 'sku')->ignore($ignore),
            ],
            'code_barres' => [
                'nullable', 'string', 'max:100',
                TenantScopedRules::uniqueInCurrentStore('produits', 'code_barres')->ignore($ignore),
            ],
            'unite' => ['sometimes', Rule::enum(ProductUnit::class)],
            'prix_achat' => ['nullable', 'numeric', 'min:0'],
            // Détail/Gros: *_price is required exactly when *_enabled is
            // true, and forbidden otherwise (docs/catalog.md §4). Both
            // rules are implicit, so they run even when the field is
            // entirely absent from a partial update payload.
            'vente_detail_active' => ['sometimes', 'boolean'],
            'prix_detail' => ['nullable', 'numeric', 'min:0', 'required_if:vente_detail_active,true', 'prohibited_unless:vente_detail_active,true'],
            'vente_gros_active' => ['sometimes', 'boolean'],
            'prix_gros' => ['nullable', 'numeric', 'min:0', 'required_if:vente_gros_active,true', 'prohibited_unless:vente_gros_active,true'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
