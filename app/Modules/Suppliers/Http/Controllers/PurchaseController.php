<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Suppliers\Exceptions\CashRegisterNotOpenException;
use App\Modules\Suppliers\Exceptions\InvalidPurchasePaymentException;
use App\Modules\Suppliers\Http\Requests\CreatePurchaseRequest;
use App\Modules\Suppliers\Http\Requests\PayPurchaseRequest;
use App\Modules\Suppliers\Http\Resources\PurchaseResource;
use App\Modules\Suppliers\Models\Purchase;
use App\Modules\Suppliers\Services\PurchaseService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Closure;
use Illuminate\Http\Request;

class PurchaseController extends ApiController
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Purchase::class, $store]);

        $status = $request->string('statut_paiement')->value();

        $purchases = Purchase::query()
            ->with('supplier')
            ->when($request->filled('fournisseur_id'), fn ($q) => $q->where('fournisseur_id', $request->integer('fournisseur_id')))
            ->when($request->filled('recherche'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('recherche').'%'))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('achete_le', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('achete_le', '<=', $request->date('au')))
            ->when($status === 'paye', fn ($q) => $q->whereColumn('montant_paye', '>=', 'montant_total'))
            ->when($status === 'non_solde', fn ($q) => $q->whereColumn('montant_paye', '<', 'montant_total'))
            ->latest('achete_le')
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(PurchaseResource::collection($purchases));
    }

    public function store(Store $store, CreatePurchaseRequest $request)
    {
        $this->authorize('create', [Purchase::class, $store]);

        return $this->guard(function () use ($store, $request) {
            $purchase = $this->purchases->create($store, $request->validated(), $request->user()?->id);

            return $this->success(new PurchaseResource($this->loadDetails($purchase)), 'Achat enregistré.', [], $purchase->wasRecentlyCreated ? 201 : 200);
        });
    }

    public function show(Store $store, Purchase $purchase)
    {
        $this->authorize('view', [$purchase, $store]);

        return $this->success(new PurchaseResource($this->loadDetails($purchase)));
    }

    /** POST /achats/{purchase}/paiements — règlement total ou partiel. */
    public function pay(Store $store, Purchase $purchase, PayPurchaseRequest $request)
    {
        $this->authorize('pay', [$purchase, $store]);

        return $this->guard(function () use ($purchase, $request) {
            $this->purchases->pay($purchase, $request->validated(), $request->user()?->id);

            return $this->success(new PurchaseResource($this->loadDetails($purchase->refresh())), 'Règlement enregistré.', [], 201);
        });
    }

    private function loadDetails(Purchase $purchase): Purchase
    {
        return $purchase->load(['supplier', 'createdBy', 'items', 'payments.createdBy']);
    }

    /** Erreurs métier → 422 avec un code exploitable par l'application. */
    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (InvalidPurchasePaymentException $e) {
            return $this->error($e->getMessage(), [], 422, 'PAIEMENT_INVALIDE');
        } catch (CashRegisterNotOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }
    }
}
