<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Http\Requests\CreateProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
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
            'selling_mode' => ['sometimes', Rule::enum(PricingMode::class)],
        ]);

        $sellingModeColumn = $request->filled('selling_mode')
            ? (PricingMode::from($request->string('selling_mode')->value()) === PricingMode::Retail ? 'retail_enabled' : 'wholesale_enabled')
            : null;

        $products = Product::query()
            ->with('category')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($sellingModeColumn, fn ($q) => $q->where($sellingModeColumn, true))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(ProductResource::collection($products));
    }

    public function store(Store $store, CreateProductRequest $request)
    {
        $this->authorize('create', [Product::class, $store]);

        $product = $this->products->create($request->validated());

        return $this->success(new ProductResource($product->load('category')), 'Produit créé avec succès.', [], 201);
    }

    public function show(Store $store, Product $product)
    {
        $this->authorize('view', [$product, $store]);

        return $this->success(new ProductResource($product->load('category')));
    }

    public function update(Store $store, Product $product, UpdateProductRequest $request)
    {
        $this->authorize('update', [$product, $store]);

        $this->products->update($product, $request->validated());

        return $this->success(new ProductResource($product->load('category')), 'Produit mis à jour.');
    }

    public function destroy(Store $store, Product $product)
    {
        $this->authorize('delete', [$product, $store]);

        $product->delete();

        return $this->success(null, 'Produit supprimé.');
    }
}
