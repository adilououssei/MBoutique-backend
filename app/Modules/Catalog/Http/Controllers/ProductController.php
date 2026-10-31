<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Http\Requests\CreateProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Sales\Services\PromotionService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends ApiController
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Product::class, $store]);

        $request->validate([
            'mode_prix' => ['sometimes', Rule::enum(PricingMode::class)],
        ]);

        $sellingModeColumn = $request->filled('mode_prix')
            ? (PricingMode::from($request->string('mode_prix')->value()) === PricingMode::Retail ? 'vente_detail_active' : 'vente_gros_active')
            : null;

        $products = Product::query()
            ->with(['category', 'supplier', 'supplements', 'parent', 'collection'])
            ->withCount('variants')
            // Nom, référence (SKU) ou code-barres exact (scan, douchette).
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = trim((string) $request->string('recherche'));
                $q->where(fn ($q) => $q->where('nom', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")->orWhere('code_barres', $term)->orWhere('dci', 'like', "%{$term}%"));
            })
            // Catalogue : articles et produits simples (sans leurs variantes) ; ou les variantes d'un article.
            ->when($request->boolean('sans_variantes'), fn ($q) => $q->whereNull('produit_parent_id'))
            // Achats, stock : ce qui porte un stock (variantes et produits simples, pas les articles à variantes).
            ->when($request->boolean('stockables'), fn ($q) => $q->whereDoesntHave('variants'))
            ->when($request->filled('parent_id'), fn ($q) => $q->where('produit_parent_id', $request->integer('parent_id')))
            ->when($request->filled('collection_id'), fn ($q) => $q->where('collection_id', $request->integer('collection_id')))
            ->when($request->filled('public_cible'), fn ($q) => $q->where('public_cible', $request->string('public_cible')))
            ->when($request->filled('taille'), fn ($q) => $q->where('taille', $request->string('taille')))
            ->when($request->filled('couleur'), fn ($q) => $q->where('couleur', $request->string('couleur')))
            // Une catégorie inclut ses sous-catégories.
            ->when($request->filled('categorie_id'), fn ($q) => $q->where(fn ($q) => $q->where('categorie_id', $request->integer('categorie_id'))
                ->orWhereIn('categorie_id', Category::query()->where('parent_id', $request->integer('categorie_id'))->select('id'))))
            ->when($request->filled('marque'), fn ($q) => $q->where('marque', $request->string('marque')))
            ->when($request->filled('modele'), fn ($q) => $q->where('modele', $request->string('modele')))
            ->when($request->filled('etat'), fn ($q) => $q->where('etat', $request->string('etat')))
            ->when($request->has('suivi_unitaire'), fn ($q) => $q->where('suivi_unitaire', $request->boolean('suivi_unitaire')))
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->when($request->has('disponible'), fn ($q) => $q->where('disponible', $request->boolean('disponible')))
            ->when($request->filled('type_article'), fn ($q) => $q->whereIn('type_article', explode(',', $request->string('type_article'))))
            ->when($sellingModeColumn, fn ($q) => $q->where($sellingModeColumn, true))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        // Vêtements : promotion en cours (prix affiché à la vente ; la vente l'applique elle-même).
        if (app(FeatureGate::class)->allows($store, 'promotions')) {
            $promotions = app(PromotionService::class);
            $products->getCollection()->each(function (Product $product) use ($promotions) {
                $price = (string) ($product->vente_detail_active ? $product->prix_detail : $product->prix_gros);
                $best = $price !== '' ? $promotions->bestFor($product, $price) : null;
                $product->setAttribute('promotion_courante', $best ? ['id' => $best['promotion']->id, 'nom' => $best['promotion']->nom, 'prix' => bcsub($price, $best['remise_unitaire'], 2)] : null);
            });
        }

        return $this->success(ProductResource::collection($products));
    }

    /**
     * GET /produits/marques — marques et modèles déjà utilisés dans la boutique :
     * suggestions de saisie et filtres (stock, ventes par marque).
     */
    public function brands(Store $store)
    {
        $this->authorize('viewAny', [Product::class, $store]);

        $rows = Product::query()->whereNotNull('marque')->where('marque', '!=', '')
            ->select(['marque', 'modele'])->distinct()->orderBy('marque')->orderBy('modele')->get();

        return $this->success($rows->groupBy('marque')->map(fn ($models, $brand) => [
            'marque' => $brand,
            'modeles' => $models->pluck('modele')->filter()->values(),
        ])->values());
    }

    public function store(Store $store, CreateProductRequest $request)
    {
        $this->authorize('create', [Product::class, $store]);

        $product = $this->products->create($request->validated());

        return $this->success(new ProductResource($product->load(['category', 'supplier', 'supplements', 'parent', 'collection'])->loadCount('variants')), 'Produit créé avec succès.', [], 201);
    }

    public function show(Store $store, Product $product)
    {
        $this->authorize('view', [$product, $store]);

        return $this->success(new ProductResource($product->load(['category', 'supplier', 'supplements', 'parent', 'collection'])->loadCount('variants')));
    }

    public function update(Store $store, Product $product, UpdateProductRequest $request)
    {
        $this->authorize('update', [$product, $store]);

        $this->products->update($product, $request->validated());

        return $this->success(new ProductResource($product->load(['category', 'supplier', 'supplements', 'parent', 'collection'])->loadCount('variants')), 'Produit mis à jour.');
    }

    public function destroy(Store $store, Product $product)
    {
        $this->authorize('delete', [$product, $store]);

        $product->delete();

        return $this->success(null, 'Produit supprimé.');
    }
}
