<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Enums\ProductCondition;
use App\Modules\Catalog\Enums\ProductConservation;
use App\Modules\Catalog\Enums\ProductKind;
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
            // Restaurant : nature, temps de préparation, disponibilité du jour, suppléments — facultatifs.
            'type_article' => ['nullable', Rule::enum(ProductKind::class)],
            'temps_preparation_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'disponible' => ['sometimes', 'boolean'],
            // Pharmacie : fiche pharmaceutique (facultative), ordonnance, conditionnement.
            'dci' => ['nullable', 'string', 'max:255'],
            'forme' => ['nullable', 'string', 'max:100'],
            'dosage' => ['nullable', 'string', 'max:100'],
            'presentation' => ['nullable', 'string', 'max:255'],
            'laboratoire' => ['nullable', 'string', 'max:255'],
            'sur_ordonnance' => ['sometimes', 'boolean'],
            'unites_par_boite' => ['nullable', 'integer', 'min:1', 'max:10000'],
            // Vêtements : variante (taille / couleur) d'un article, public cible, collection.
            'produit_parent_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')->whereNull('produit_parent_id')],
            'taille' => ['nullable', 'string', 'max:30'],
            'couleur' => ['nullable', 'string', 'max:50'],
            'public_cible' => ['nullable', Rule::in(['homme', 'femme', 'enfant', 'bebe', 'mixte'])],
            'collection_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('collections')],
            'supplement_ids' => ['nullable', 'array', 'max:20'],
            'supplement_ids.*' => ['integer', 'distinct', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            // Vente au poids (boucherie) : quantités proposées à la pesée (¼ kg, ½ kg, 2,5 kg…).
            'quantites_vente' => ['nullable', 'array', 'max:12'],
            'quantites_vente.*' => ['numeric', 'gt:0', 'max:100000', 'distinct'],
            // Fiche technique (électronique) : facultative, rien n'est exigé d'un accessoire.
            'marque' => ['nullable', 'string', 'max:100'],
            'modele' => ['nullable', 'string', 'max:100'],
            'etat' => ['nullable', Rule::enum(ProductCondition::class)],
            'caracteristiques' => ['nullable', 'string', 'max:2000'],
            'fournisseur_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('fournisseurs')->whereNull('deleted_at')],
            // Suivi à l'unité : chaque appareil vendu est identifié par son IMEI ou numéro de série.
            'suivi_unitaire' => ['sometimes', 'boolean'],
            // Garantie offerte à la vente (électronique), facultative.
            'garantie_mois' => ['nullable', 'integer', 'min:0', 'max:120'],
            'conditions_garantie' => ['nullable', 'string', 'max:2000'],
            // Conservation (boucherie) : facultatifs, sans effet pour les autres domaines.
            'conservation' => ['nullable', Rule::enum(ProductConservation::class)],
            'duree_conservation_jours' => ['nullable', 'integer', 'min:0', 'max:3650'],
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
