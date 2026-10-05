<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidStockTransferException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Inventory\Http\Requests\CreateStockTransferRequest;
use App\Modules\Inventory\Http\Resources\StockTransferResource;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

/** Transferts de stock entre boutiques — docs/inventory.md §"Transferts". */
class StockTransferController extends ApiController
{
    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [StockMovement::class, $store]);

        $transfers = StockTransfer::query()
            ->involving($store)
            ->when($request->input('sens') === 'sortant', fn ($q) => $q->where('boutique_source_id', $store->id))
            ->when($request->input('sens') === 'entrant', fn ($q) => $q->where('boutique_destination_id', $store->id))
            ->with(['source', 'destination', 'lines', 'createdBy'])
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(StockTransferResource::collection($transfers));
    }

    public function show(Store $store, int $transfer)
    {
        $this->authorize('viewAny', [StockMovement::class, $store]);

        $transfer = StockTransfer::query()->involving($store)->with(['source', 'destination', 'lines', 'createdBy'])->findOrFail($transfer);

        return $this->success(new StockTransferResource($transfer));
    }

    /** GET transferts/destinations — boutiques où l'utilisateur peut envoyer de la marchandise. */
    public function destinations(Store $store, Request $request)
    {
        $this->authorize('create', [StockMovement::class, $store, StockMovementType::TransferOut]);

        return $this->success($this->transfers->destinationsFor($store, $request->user())
            ->map(fn (Store $s) => ['id' => $s->id, 'nom' => $s->nom])
            ->all());
    }

    public function store(Store $store, CreateStockTransferRequest $request)
    {
        $this->authorize('create', [StockMovement::class, $store, StockMovementType::TransferOut]);

        try {
            $transfer = $this->transfers->transfer($store, $request->validated(), $request->user());
        } catch (InvalidStockTransferException $e) {
            return $this->error($e->getMessage(), [], 422, 'TRANSFERT_INVALIDE');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_NON_INITIALISE');
        }

        return $this->success(
            new StockTransferResource($transfer->load(['source', 'destination', 'lines', 'createdBy'])),
            'Transfert effectué.',
            [],
            201,
        );
    }
}
