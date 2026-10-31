<?php

namespace App\Modules\Returns\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Devices\Exceptions\DeviceTrackingException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Returns\Exceptions\ReturnException;
use App\Modules\Returns\Http\Resources\SaleReturnResource;
use App\Modules\Returns\Models\SaleReturn;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Retours d'articles vendus : même droit que l'annulation d'une vente (`ventes.annuler`). */
class ReturnController extends ApiController
{
    public function __construct(private readonly ReturnService $returns) {}

    public function index(Store $store, Request $request)
    {
        abort_unless($request->user()->can('ventes.voir'), 403);
        $items = SaleReturn::query()->with(['sale', 'customer', 'lines', 'createdBy'])
            ->when($request->filled('vente_id'), fn ($q) => $q->where('vente_id', $request->integer('vente_id')))
            ->when($request->filled('statut_verification'), fn ($q) => $q->where('statut_verification', $request->string('statut_verification')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(SaleReturnResource::collection($items));
    }

    public function store(Store $store, Request $request)
    {
        abort_unless($request->user()->can('ventes.annuler'), 403);
        $data = $request->validate([
            'vente_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('ventes')],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.ligne_vente_id' => ['required', 'integer', 'distinct'],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.appareil_ids' => ['nullable', 'array'],
            'lignes.*.appareil_ids.*' => ['integer', 'distinct'],
            'motif' => ['required', 'string', 'max:500'],
            'decision' => ['required', Rule::in(['remboursement', 'avoir'])],
            'etat_produits' => ['required', Rule::in(['revendable', 'defectueux', 'a_verifier'])],
            'mode_remboursement' => ['nullable', Rule::in(['especes', 'hors_caisse'])],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ]);

        try {
            $return = $this->returns->record(Sale::query()->findOrFail($data['vente_id']), $data, $request->user()?->id);
        } catch (ReturnException|DeviceTrackingException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }

        return $this->success(new SaleReturnResource($return->load(['sale', 'customer', 'lines', 'createdBy'])), 'Retour enregistré.', [], 201);
    }

    /** POST /retours/echange — retour + nouvelle vente en une opération (taille, couleur ou autre article). */
    public function exchange(Store $store, Request $request)
    {
        abort_unless($request->user()->can('ventes.annuler') && $request->user()->can('ventes.creer'), 403);
        $data = $request->validate([
            'vente_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('ventes')],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.ligne_vente_id' => ['required', 'integer', 'distinct'],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.appareil_ids' => ['nullable', 'array'],
            'motif' => ['required', 'string', 'max:500'],
            'etat_produits' => ['required', Rule::in(['revendable', 'defectueux', 'a_verifier'])],
            'nouvelles_lignes' => ['required', 'array', 'min:1'],
            'nouvelles_lignes.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'nouvelles_lignes.*.mode_prix' => ['nullable', Rule::in(['detail', 'gros'])],
            'nouvelles_lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'mode_paiement' => ['nullable', Rule::in(['especes', 'mobile_money', 'carte', 'virement'])],
            'ecart' => ['nullable', Rule::in(['especes', 'avoir'])],
        ]);

        try {
            $return = $this->returns->exchange(Sale::query()->findOrFail($data['vente_id']), $data, $request->user()?->id);
        } catch (ReturnException|DeviceTrackingException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_NON_INITIALISE');
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        }

        return $this->success(new SaleReturnResource($return->load(['sale', 'customer', 'lines', 'createdBy'])), 'Échange enregistré.', [], 201);
    }

    /** POST /retours/{id}/verification — `decision` reintegrer|detruire (stock.ajuster : pharmacien, gérant). */
    public function verify(Store $store, SaleReturn $saleReturn, Request $request)
    {
        abort_unless($request->user()->can('stock.ajuster'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['reintegrer', 'detruire'])], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $return = $this->returns->verify($saleReturn, $data['decision'], $data['note'] ?? null, $request->user()?->id);
        } catch (ReturnException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        }

        return $this->success(new SaleReturnResource($return->load(['sale', 'customer', 'lines', 'createdBy'])), $data['decision'] === 'reintegrer' ? 'Produit réintégré au stock.' : 'Produit détruit : perte enregistrée.');
    }
}
