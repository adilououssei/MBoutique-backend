<?php

namespace App\Modules\Sales\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sales\Models\Promotion;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Collection;

/**
 * Meilleure promotion en cours pour un produit. Une variante profite des
 * promotions de son article ; une catégorie, de celles de sa catégorie parente.
 * Remise par unité : % du prix, ou montant fixe (jamais au-delà du prix).
 */
class PromotionService
{
    /** @var Collection<int, Promotion>|null */
    private ?Collection $running = null;

    /** @return array{promotion: Promotion, remise_unitaire: string}|null */
    public function bestFor(Product $product, string $unitPrice): ?array
    {
        $best = null;
        foreach ($this->running() as $promotion) {
            if (! $this->applies($promotion, $product)) {
                continue;
            }
            $discount = $promotion->type === 'pourcentage'
                ? Money::round(bcdiv(bcmul($unitPrice, (string) $promotion->valeur, 6), '100', 6))
                : Money::round((string) $promotion->valeur);
            if (bccomp($discount, $unitPrice, 2) > 0) {
                $discount = $unitPrice;
            }
            if ($best === null || bccomp($discount, $best['remise_unitaire'], 2) > 0) {
                $best = ['promotion' => $promotion, 'remise_unitaire' => $discount];
            }
        }

        return $best;
    }

    /** Nouvelle requête (et nouvelle boutique) : on ne garde pas de cache entre deux ventes. */
    public function forget(): void
    {
        $this->running = null;
    }

    private function applies(Promotion $promotion, Product $product): bool
    {
        $ids = array_filter([$product->id, $product->produit_parent_id]);

        return match ($promotion->portee) {
            'tous' => true,
            'produits' => $promotion->products->pluck('id')->intersect($ids)->isNotEmpty(),
            'categorie' => $product->categorie_id !== null && ($product->categorie_id === $promotion->categorie_id
                || Category::query()->whereKey($product->categorie_id)->value('parent_id') === $promotion->categorie_id),
            'collection' => $product->collection_id !== null && $product->collection_id === $promotion->collection_id,
            default => false,
        };
    }

    /** @return Collection<int, Promotion> */
    private function running(): Collection
    {
        return $this->running ??= Promotion::query()->running()->with('products:id')->get();
    }
}
