<?php

namespace App\Modules\Repairs\Http\Controllers;

use App\Modules\Features\Services\FeatureGate;
use App\Modules\Repairs\Exceptions\RepairException;
use App\Modules\Repairs\Http\Resources\QuoteResource;
use App\Modules\Repairs\Http\Resources\RepairResource;
use App\Modules\Repairs\Models\Quote;
use App\Modules\Repairs\Services\QuoteService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Devis (feature `devis`). Lecture : reparations.voir ; écriture :
 * reparations.gerer (mêmes personnes que les dossiers de travaux).
 */
class QuoteController extends ApiController
{
    private const RELATIONS = ['customer', 'lines', 'repair', 'createdBy'];

    public function __construct(private readonly QuoteService $quotes) {}

    /** `statut` (dont `expire`), `client_id`, `recherche`. */
    public function index(Store $store, Request $request)
    {
        $this->can($request, 'reparations.voir');
        $status = (string) $request->string('statut');
        $today = now()->toDateString();
        $items = Quote::query()->with(['customer', 'repair'])
            ->when($status === 'expire', fn ($q) => $q->whereIn('statut', ['brouillon', 'envoye'])->whereDate('valide_jusqu_au', '<', $today))
            ->when(in_array($status, ['brouillon', 'envoye'], true), fn ($q) => $q->where('statut', $status)->where(fn ($q) => $q->whereNull('valide_jusqu_au')->orWhereDate('valide_jusqu_au', '>=', $today)))
            ->when(in_array($status, ['accepte', 'refuse', 'transforme'], true), fn ($q) => $q->where('statut', $status))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.trim((string) $request->string('recherche')).'%';
                $q->where(fn ($q) => $q->where('numero', 'like', $term)->orWhere('objet', 'like', $term)->orWhere('nom_client', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('nom', 'like', $term)->orWhere('telephone', 'like', $term)));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(QuoteResource::collection($items));
    }

    public function store(Store $store, Request $request)
    {
        $this->can($request, 'reparations.gerer');

        return $this->guard(fn () => $this->success(new QuoteResource($this->quotes->save(null, $this->validated($request, true), $request->user()?->id)->load(self::RELATIONS)), 'Devis enregistré.', [], 201));
    }

    public function show(Store $store, Quote $quote, Request $request)
    {
        $this->can($request, 'reparations.voir');

        return $this->success(new QuoteResource($quote->load(self::RELATIONS)));
    }

    public function update(Store $store, Quote $quote, Request $request)
    {
        $this->can($request, 'reparations.gerer');

        return $this->guard(fn () => $this->success(new QuoteResource($this->quotes->save($quote, $this->validated($request, false), $request->user()?->id)->load(self::RELATIONS)), 'Devis mis à jour.'));
    }

    /** POST /devis/{id}/statut — envoye, accepte, refuse. */
    public function changeStatus(Store $store, Quote $quote, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate(['statut' => ['required', Rule::in(['brouillon', 'envoye', 'accepte', 'refuse'])]]);

        return $this->guard(fn () => $this->success(new QuoteResource($this->quotes->changeStatus($quote, $data['statut'])->load(self::RELATIONS))));
    }

    /** POST /devis/{id}/transformer — crée le dossier de travaux. */
    public function convert(Store $store, Quote $quote, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        abort_unless(app(FeatureGate::class)->allows($store, 'reparations'), 403);
        $data = $request->validate([
            'appareil' => ['nullable', 'string', 'max:255'],
            'etat_reception' => ['nullable', 'string', 'max:2000'],
            'prevu_le' => ['nullable', 'date'],
            'technicien_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')],
            'techniciens' => ['nullable', 'array'],
            'techniciens.*' => ['integer', TenantScopedRules::existsInCurrentStore('employes')],
        ]);

        return $this->guard(fn () => $this->success(new RepairResource($this->quotes->convert($quote, $data, $request->user()?->id)->load(['customer', 'technician', 'technicians', 'services', 'parts', 'payments', 'followUps', 'quote'])), 'Dossier de travaux créé.', [], 201));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')],
            'nom_client' => ['nullable', 'string', 'max:255'],
            'telephone_client' => ['nullable', 'string', 'max:30'],
            'nouveau_client' => ['nullable', 'array'],
            'nouveau_client.nom' => ['required_with:nouveau_client', 'string', 'max:255'],
            'nouveau_client.telephone' => ['nullable', 'string', 'max:30'],
            'objet' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'valide_jusqu_au' => ['nullable', 'date'],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lignes' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lignes.*.type' => ['nullable', Rule::in(['prestation', 'materiau', 'main_oeuvre', 'frais'])],
            'lignes.*.service_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('services')],
            'lignes.*.produit_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'lignes.*.libelle' => ['nullable', 'string', 'max:255'],
            'lignes.*.quantite' => ['nullable', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    private function guard(\Closure $operation)
    {
        try {
            return $operation();
        } catch (RepairException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        }
    }
}
