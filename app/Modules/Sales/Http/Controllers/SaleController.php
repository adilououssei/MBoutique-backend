<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Conservation\Exceptions\LotNotSellableException;
use App\Modules\Customers\Exceptions\PrescriptionException;
use App\Modules\Devices\Exceptions\DeviceTrackingException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Exceptions\SaleAlreadyCancelledException;
use App\Modules\Sales\Http\Requests\CancelSaleRequest;
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
            ->when($request->filled('recherche'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('recherche').'%'))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('vendue_le', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('vendue_le', '<=', $request->date('au')))
            ->when($request->filled('mode_paiement'), fn ($q) => $q->where('mode_paiement', $request->string('mode_paiement')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('vendeur_id'), fn ($q) => $q->where('vendeur_id', $request->integer('vendeur_id')))
            ->latest('vendue_le')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(SaleResource::collection($sales));
    }

    public function checkout(Store $store, CreateSaleCheckoutRequest $request)
    {
        $this->authorize('create', [Sale::class, $store]);
        if ($request->input('mode_paiement') === PaymentMethod::Credit->value) {
            $this->authorize('sellOnCredit', [Sale::class, $store]);
        }

        try {
            $sale = $this->sales->checkout($store, $request->validated(), $request->user()?->id);
        } catch (PrescriptionException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (SalonException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (LotNotSellableException $e) {
            return $this->error($e->getMessage(), [], 422, 'LOT_NON_VENDABLE');
        } catch (DeviceTrackingException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (PricingModeNotAvailableException $e) {
            return $this->error($e->getMessage(), [], 422, 'MODE_PRIX_INDISPONIBLE');
        } catch (InvalidDiscountException $e) {
            return $this->error($e->getMessage(), [], 422, 'REMISE_INVALIDE');
        } catch (InvalidDepositException $e) {
            return $this->error($e->getMessage(), [], 422, 'ACOMPTE_INVALIDE');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            // Un produit jamais stocké (ex. juste importé depuis Excel) :
            // erreur métier explicite, pas une 500.
            return $this->error($e->getMessage().' Initialisez-le depuis la fiche du produit avant de le vendre.', [], 422, 'STOCK_NON_INITIALISE');
        }

        $sale->load(['customer', 'soldBy', 'items.devices', 'items.warranties', 'items.returnLines']);

        $wasJustCreated = $sale->wasRecentlyCreated;

        return $this->success(new SaleResource($sale), 'Vente enregistrée.', [], $wasJustCreated ? 201 : 200);
    }

    public function show(Store $store, Sale $sale)
    {
        $this->authorize('view', [$sale, $store]);

        return $this->success(new SaleResource($sale->load(['customer', 'soldBy', 'cancelledBy', 'items.devices', 'items.warranties', 'items.returnLines'])));
    }

    /**
     * POST /ventes/{sale}/annuler — annulation totale : remise en stock des
     * produits et remboursement en caisse, en une seule transaction.
     * Voir docs/sales.md §20.
     */
    public function cancel(Store $store, Sale $sale, CancelSaleRequest $request)
    {
        $this->authorize('cancel', [$sale, $store]);

        try {
            $sale = $this->sales->cancel($sale, $request->validated(), $request->user()?->id);
        } catch (SaleAlreadyCancelledException $e) {
            return $this->error($e->getMessage(), [], 422, 'VENTE_DEJA_ANNULEE');
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }

        return $this->success(new SaleResource($sale->load(['customer', 'soldBy', 'cancelledBy', 'items.devices', 'items.warranties', 'items.returnLines'])), 'Vente annulée et remboursée.');
    }
}
