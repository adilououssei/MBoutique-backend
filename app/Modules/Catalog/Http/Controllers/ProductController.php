<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\CreateProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Product::class, $store]);

        $products = Product::query()
            ->with('category')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(ProductResource::collection($products));
    }

    public function store(Store $store, CreateProductRequest $request)
    {
        $this->authorize('create', [Product::class, $store]);

        $product = Product::create($request->validated());

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

        $product->update($request->validated());

        return $this->success(new ProductResource($product->load('category')), 'Produit mis à jour.');
    }

    public function destroy(Store $store, Product $product)
    {
        $this->authorize('delete', [$product, $store]);

        $product->delete();

        return $this->success(null, 'Produit supprimé.');
    }
}
