<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Models\CustomerPackage;
use App\Modules\Appointments\Models\Package;
use App\Modules\Appointments\Services\PackageService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Forfaits du salon : définition (prestations incluses, séances, prix,
 * validité), vente à un client (une vente ordinaire avec une ligne
 * forfait), forfaits des clients avec séances restantes et expirés.
 */
class PackageController extends ApiController
{
    public function __construct(private readonly PackageService $packages, private readonly SaleService $sales) {}

    public function index(Store $store, Request $request)
    {
        abort_unless($request->user()->can('services.voir'), 403);
        $packages = Package::query()->with('services:id,nom')
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')->get();

        return $this->success($packages->map(fn (Package $p) => $this->present($p))->values());
    }

    public function store(Store $store, Request $request)
    {
        abort_unless($request->user()->can('services.creer'), 403);

        return $this->success($this->present($this->packages->save(null, $this->validated($request, true))), 'Forfait créé.', [], 201);
    }

    public function update(Store $store, Package $package, Request $request)
    {
        abort_unless($request->user()->can('services.modifier'), 403);

        return $this->success($this->present($this->packages->save($package, $this->validated($request, false))), 'Forfait mis à jour.');
    }

    public function destroy(Store $store, Package $package, Request $request)
    {
        abort_unless($request->user()->can('services.supprimer'), 403);
        $package->delete(); // les forfaits déjà vendus restent valables

        return $this->success(null, 'Forfait retiré de la vente.');
    }

    /** POST forfaits/{package}/vendre { client_id, caisse_id, mode_paiement? } — encaisse le forfait. */
    public function sell(Store $store, Package $package, Request $request)
    {
        abort_unless($request->user()->can('ventes.creer'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('clients')->whereNull('deleted_at')],
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'mode_paiement' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'acompte' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $sale = $this->sales->checkout($store, [
                'caisse_id' => $data['caisse_id'],
                'client_id' => $data['client_id'],
                'mode_paiement' => $data['mode_paiement'] ?? 'especes',
                'acompte' => $data['acompte'] ?? null,
                'lignes' => [['forfait_id' => $package->id, 'quantite' => '1']],
            ], $request->user()?->id);
        } catch (SalonException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InvalidDepositException $e) {
            return $this->error($e->getMessage(), [], 422, 'ACOMPTE_INVALIDE');
        }
        $sold = CustomerPackage::query()->with(['package', 'customer'])->where('vente_id', $sale->id)->firstOrFail();

        return $this->success($this->presentCustomerPackage($sold) + ['vente_id' => $sale->id], 'Forfait vendu.', [], 201);
    }

    /** GET forfaits-clients?client_id=&statut=actif|epuise|expire|annule */
    public function customerPackages(Store $store, Request $request)
    {
        abort_unless($request->user()->can('clients.voir'), 403);
        $request->validate(['client_id' => ['nullable', 'integer'], 'statut' => ['nullable', 'in:actif,epuise,expire,annule']]);
        $status = $request->string('statut')->toString();

        $rows = CustomerPackage::query()->with(['package', 'customer'])
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($status === 'actif', fn ($q) => $q->where('statut', 'actif')->where(fn ($w) => $w->whereNull('expire_le')->orWhere('expire_le', '>=', today()->toDateString())))
            ->when($status === 'expire', fn ($q) => $q->where('statut', 'actif')->where('expire_le', '<', today()->toDateString()))
            ->when(in_array($status, ['epuise', 'annule'], true), fn ($q) => $q->where('statut', $status))
            ->latest('achete_le')
            ->limit(200)
            ->get();

        return $this->success($rows->map(fn (CustomerPackage $c) => $this->presentCustomerPackage($c))->values());
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'nom' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'prix' => [$required, 'numeric', 'min:0'],
            'nombre_seances' => [$required, 'integer', 'min:1', 'max:500'],
            'validite_jours' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'actif' => ['sometimes', 'boolean'],
            'services' => [$required, 'array', 'min:1'],
            'services.*' => ['integer', TenantScopedRules::existsInCurrentStore('services')->whereNull('deleted_at')],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Package $package): array
    {
        return [
            'id' => $package->id,
            'nom' => $package->nom,
            'description' => $package->description,
            'prix' => $package->prix,
            'nombre_seances' => $package->nombre_seances,
            'validite_jours' => $package->validite_jours,
            'actif' => $package->actif,
            'services' => $package->services->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentCustomerPackage(CustomerPackage $c): array
    {
        return [
            'id' => $c->id,
            'forfait' => ['id' => $c->forfait_id, 'nom' => $c->package?->nom],
            'client' => ['id' => $c->client_id, 'nom' => $c->customer?->nom, 'telephone' => $c->customer?->telephone],
            'prix' => $c->prix,
            'seances_total' => $c->seances_total,
            'seances_utilisees' => $c->seances_utilisees,
            'seances_restantes' => $c->remaining(),
            'achete_le' => $c->achete_le,
            'expire_le' => $c->expire_le?->toDateString(),
            'statut' => $c->displayStatus(),
            'vente_id' => $c->vente_id,
        ];
    }
}
