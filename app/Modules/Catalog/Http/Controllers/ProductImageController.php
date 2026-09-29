<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\UploadProductImageRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Photo optionnelle d'un produit, gérée à part du CRUD JSON (création et
 * modification restent en JSON ; l'image est un envoi multipart distinct).
 * Même autorisation que la modification du produit.
 */
class ProductImageController extends ApiController
{
    public function store(Store $store, Product $product, UploadProductImageRequest $request)
    {
        $this->authorize('update', [$product, $store]);

        $file = $request->file('image');
        $path = $file->storeAs(
            "produits/{$store->id}",
            Str::uuid().'.'.$file->extension(),
            'public',
        );

        $previous = $product->image;
        $product->forceFill(['image' => $path])->save();

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return $this->success(new ProductResource($product->load('category')), 'Image du produit enregistrée.');
    }

    public function destroy(Store $store, Product $product)
    {
        $this->authorize('update', [$product, $store]);

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
            $product->forceFill(['image' => null])->save();
        }

        return $this->success(new ProductResource($product->load('category')), 'Image du produit supprimée.');
    }
}
