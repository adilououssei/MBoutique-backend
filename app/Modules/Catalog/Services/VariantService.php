<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Variantes d'un article de mode : une variante = un produit rattaché
 * (`produit_parent_id`) avec sa taille et sa couleur. Elle hérite des
 * informations de l'article ; son stock, son prix et son code-barres lui
 * sont propres. L'article lui-même n'a ni stock ni vente.
 */
class VariantService
{
    private const INHERITED = ['categorie_id', 'fournisseur_id', 'description', 'marque', 'modele', 'etat', 'public_cible', 'collection_id', 'unite', 'prix_achat', 'vente_detail_active', 'prix_detail', 'vente_gros_active', 'prix_gros', 'image'];

    /**
     * Crée les combinaisons taille × couleur manquantes (une liste vide = sans
     * cette dimension). Les variantes existantes sont conservées telles quelles.
     *
     * @param  array<int, string>  $sizes
     * @param  array<int, string>  $colors
     * @return Collection<int, Product> les variantes créées
     */
    public function generate(Product $product, array $sizes, array $colors): Collection
    {
        if ($product->produit_parent_id !== null) {
            throw new InvalidArgumentException('Une variante ne peut pas avoir elle-même de variantes.');
        }
        $sizes = array_values(array_unique(array_filter(array_map('trim', $sizes)))) ?: [null];
        $colors = array_values(array_unique(array_filter(array_map('trim', $colors)))) ?: [null];
        if ($sizes === [null] && $colors === [null]) {
            throw new InvalidArgumentException('Indiquez au moins une taille ou une couleur.');
        }

        return DB::transaction(function () use ($product, $sizes, $colors) {
            $existing = $product->variants()->get()->map(fn (Product $v) => mb_strtolower(($v->taille ?? '').'|'.($v->couleur ?? '')))->all();
            $created = collect();
            foreach ($sizes as $size) {
                foreach ($colors as $color) {
                    if (in_array(mb_strtolower(($size ?? '').'|'.($color ?? '')), $existing, true)) {
                        continue;
                    }
                    $suffix = implode(' / ', array_filter([$size, $color]));
                    $variant = new Product([
                        ...$product->only(self::INHERITED),
                        'nom' => "{$product->nom} — {$suffix}",
                        'produit_parent_id' => $product->id,
                        'taille' => $size,
                        'couleur' => $color,
                        'sku' => $product->sku ? $product->sku.'-'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $suffix)) : null,
                        'actif' => $product->actif,
                    ]);
                    $variant->forceFill(['image' => $product->image, 'slug' => $this->slug($product, $suffix)])->save();
                    $created->push($variant);
                }
            }

            return $created;
        });
    }

    /** Renommage de l'article : ses variantes suivent ; taille / couleur d'une variante modifiée : son nom suit. */
    public function syncNames(Product $product): void
    {
        if ($product->produit_parent_id !== null) {
            $parent = $product->parent;
            if ($parent !== null && ($product->wasChanged('taille') || $product->wasChanged('couleur'))) {
                $product->forceFill(['nom' => "{$parent->nom} — ".implode(' / ', array_filter([$product->taille, $product->couleur]))])->saveQuietly();
            }

            return;
        }
        if ($product->wasChanged('nom')) {
            foreach ($product->variants()->get() as $variant) {
                $variant->forceFill(['nom' => "{$product->nom} — ".implode(' / ', array_filter([$variant->taille, $variant->couleur]))])->saveQuietly();
            }
        }
    }

    private function slug(Product $product, string $suffix): string
    {
        $base = str($product->slug ?? $product->nom)->slug()->append('-', str($suffix)->slug());
        $slug = (string) $base;
        $i = 2;
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
