<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\VariantService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Variantes d'un article (feature `variantes`) : GET /produits/{id}/variantes
 * (grille taille × couleur avec stock), POST (génère les combinaisons).
 * Mêmes droits que le catalogue (voir, créer).
 */
class VariantController extends ApiController
{
    public function __construct(private readonly VariantService $variants) {}

    public function index(Store $store, Product $product)
    {
        $this->authorize('view', [$product, $store]);
        $variants = $product->variants()->orderBy('id')->get();
        $stocks = DB::table('stocks')->where('boutique_id', $store->id)->whereIn('produit_id', $variants->pluck('id'))->get()->keyBy('produit_id');

        return $this->success([
            'article' => ['id' => $product->id, 'nom' => $product->nom],
            'tailles' => $variants->pluck('taille')->filter()->unique()->values(),
            'couleurs' => $variants->pluck('couleur')->filter()->unique()->values(),
            'variantes' => $variants->map(fn (Product $v) => [
                ...(new ProductResource($v))->resolve(),
                'stock' => isset($stocks[$v->id]) ? number_format((float) $stocks[$v->id]->quantite, 3, '.', '') : null,
                'stock_minimum' => isset($stocks[$v->id]) ? $stocks[$v->id]->quantite_minimum : null,
            ])->values(),
            'stock_total' => number_format((float) $stocks->sum('quantite'), 3, '.', ''),
        ]);
    }

    public function store(Store $store, Product $product, Request $request)
    {
        $this->authorize('create', [Product::class, $store]);
        $data = $request->validate([
            'tailles' => ['nullable', 'array', 'max:40'],
            'tailles.*' => ['string', 'max:30'],
            'couleurs' => ['nullable', 'array', 'max:30'],
            'couleurs.*' => ['string', 'max:50'],
        ]);

        try {
            $created = $this->variants->generate($product, $data['tailles'] ?? [], $data['couleurs'] ?? []);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), [], 422, 'VARIANTES_INVALIDES');
        }

        return $this->success(ProductResource::collection($created), "{$created->count()} variante(s) créée(s).", [], 201);
    }
}
