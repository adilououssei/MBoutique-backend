<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockAlreadyInitializedException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Inventory\Http\Requests\CreateStockMovementRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class StockMovementController extends ApiController
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Store $store, Product $product, Request $request)
    {
        $this->authorize('viewAny', [StockMovement::class, $store]);

        $movements = StockMovement::query()
            ->where('produit_id', $product->id)
            ->with('createdBy')
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(StockMovementResource::collection($movements));
    }

    public function store(Store $store, Product $product, CreateStockMovementRequest $request)
    {
        $type = $request->type();

        $this->authorize('create', [StockMovement::class, $store, $type]);

        $userId = $request->user()?->id;
        $reason = $request->input('motif');

        try {
            $movement = match (true) {
                $type === StockMovementType::Initial => $this->inventory->initializeStock(
                    $product,
                    (string) $request->input('quantite'),
                    $request->filled('quantite_minimum') ? (string) $request->input('quantite_minimum') : null,
                    $userId,
                    $reason,
                ),
                $type === StockMovementType::Stocktake => $this->inventory->stocktake(
                    $product,
                    (string) $request->input('quantite_comptee'),
                    $userId,
                    $reason,
                ),
                $type->isEntry() => $this->inventory->addStock($product, $type, (string) $request->input('quantite'), $userId, $reason),
                default => $this->inventory->removeStock($product, $type, (string) $request->input('quantite'), $userId, $reason),
            };
        } catch (StockAlreadyInitializedException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_DEJA_INITIALISE');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_NON_INITIALISE');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        }

        return $this->success(new StockMovementResource($movement), 'Mouvement de stock enregistré.', [], 201);
    }
}
