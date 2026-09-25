<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Inventory\Http\Requests\UpdateStockRequest;
use App\Modules\Inventory\Http\Resources\StockResource;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class StockController extends ApiController
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Lists existing Stock rows only — a Product with no movement yet
     * simply has none (docs/inventory.md §"Initialisation") and won't
     * appear here; use show() for a single product's (possibly virtual
     * zero) state.
     */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Stock::class, $store]);

        $stocks = Stock::query()
            ->with('product')
            ->when($request->filled('search'), fn ($q) => $q->whereHas(
                'product',
                fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')
            ))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereNotNull('minimum_quantity')->whereColumn('quantity', '<=', 'minimum_quantity'))
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(StockResource::collection($stocks));
    }

    /** Returns a zero-quantity, uninitialized-state response rather than 404 when no Stock row exists yet. */
    public function show(Store $store, Product $product)
    {
        $this->authorize('viewAny', [Stock::class, $store]);

        $stock = Stock::query()->where('product_id', $product->id)->first()
            ?? Stock::make(['product_id' => $product->id, 'quantity' => 0]);

        $stock->setRelation('product', $product);

        return $this->success(new StockResource($stock));
    }

    public function update(Store $store, Product $product, UpdateStockRequest $request)
    {
        $this->authorize('update', [Stock::class, $store]);

        try {
            $stock = $this->inventory->updateMinimumQuantity($product, $request->input('minimum_quantity'));
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage(), [], 404, 'STOCK_NOT_INITIALIZED');
        }

        $stock->setRelation('product', $product);

        return $this->success(new StockResource($stock), 'Seuil de stock mis à jour.');
    }
}
