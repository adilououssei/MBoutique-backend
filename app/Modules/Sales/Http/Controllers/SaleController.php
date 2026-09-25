<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Http\Requests\CreateSaleCheckoutRequest;
use App\Modules\Sales\Http\Resources\SaleResource;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class SaleController extends ApiController
{
    public function __construct(private readonly SaleService $sales) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Sale::class, $store]);

        $sales = Sale::query()
            ->with('customer')
            ->when($request->filled('search'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sold_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sold_at', '<=', $request->date('to')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('sold_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(SaleResource::collection($sales));
    }

    public function checkout(Store $store, CreateSaleCheckoutRequest $request)
    {
        $this->authorize('create', [Sale::class, $store]);

        try {
            $sale = $this->sales->checkout($store, $request->validated(), $request->user()?->id);
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'NO_OPEN_CASH_REGISTER_SESSION');
        } catch (PricingModeNotAvailableException $e) {
            return $this->error($e->getMessage(), [], 422, 'PRICING_MODE_NOT_AVAILABLE');
        } catch (InvalidDiscountException $e) {
            return $this->error($e->getMessage(), [], 422, 'INVALID_DISCOUNT');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'INSUFFICIENT_STOCK');
        }

        $sale->load(['customer', 'soldBy', 'items']);

        $wasJustCreated = $sale->wasRecentlyCreated;

        return $this->success(new SaleResource($sale), 'Vente enregistrée.', [], $wasJustCreated ? 201 : 200);
    }

    public function show(Store $store, Sale $sale)
    {
        $this->authorize('view', [$sale, $store]);

        return $this->success(new SaleResource($sale->load(['customer', 'soldBy', 'items'])));
    }
}
