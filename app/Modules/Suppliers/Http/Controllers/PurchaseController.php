<?php

namespace App\Modules\Suppliers\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Devices\Exceptions\DeviceTrackingException;
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
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('numero_facture_fournisseur', 'like', $term)->orWhere('reference_commande', 'like', $term));
            })
            ->when($request->filled('du'), fn ($q) => $q->whereDate('achete_le', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('achete_le', '<=', $request->date('au')))
            ->when($status === 'paye', fn ($q) => $q->where('statut', 'recu')->whereColumn('montant_paye', '>=', 'montant_total'))
            ->when($status === 'non_solde', fn ($q) => $q->where('statut', 'recu')->whereColumn('montant_paye', '<', 'montant_total'))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
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

    /** POST /achats/{purchase}/reception — commande reçue et vérifiée : stock, lots, dette. */
    public function receive(Store $store, Purchase $purchase, Request $request)
    {
        $this->authorize('create', [Purchase::class, $store]);
        $data = $request->validate([
            'numero_facture_fournisseur' => ['nullable', 'string', 'max:100'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.ligne_id' => ['required', 'integer', 'distinct'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0'],
            'lignes.*.cout_unitaire' => ['nullable', 'numeric', 'min:0'],
            'lignes.*.numero_lot' => ['nullable', 'string', 'max:100'],
            'lignes.*.date_peremption' => ['nullable', 'date'],
            'lignes.*.fabrique_le' => ['nullable', 'date'],
        ]);

        return $this->guard(fn () => $this->success(new PurchaseResource($this->loadDetails($this->purchases->receive($store, $purchase, $data, $request->user()?->id))), 'Réception enregistrée : le stock est à jour.'));
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
        } catch (DeviceTrackingException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }
    }
}
