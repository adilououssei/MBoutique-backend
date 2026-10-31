<?php

namespace App\Modules\Warranties\Http\Controllers;

use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Exceptions\WarrantyException;
use App\Modules\Warranties\Http\Resources\WarrantyResource;
use App\Modules\Warranties\Models\Warranty;
use App\Modules\Warranties\Models\WarrantyClaim;
use App\Modules\Warranties\Services\WarrantyService;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarrantyController extends ApiController
{
    public function __construct(private readonly WarrantyService $warranties) {}

    /**
     * `recherche` (n° de garantie, IMEI, n° de série, référence de vente, client, téléphone),
     * `etat` (active, expiree, annulee, reclamation), `client_id`, `vente_id`.
     */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Warranty::class, $store]);
        $state = $request->string('etat')->value();

        $items = Warranty::query()
            ->with(['sale', 'device', 'customer'])
            ->withCount(['claims as open_claims' => fn ($q) => $q->whereIn('statut', WarrantyClaim::OPEN_STATUSES)])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.trim((string) $request->string('recherche')).'%';
                $q->where(fn ($q) => $q->where('numero', 'like', $term)
                    ->orWhereHas('device', fn ($d) => $d->where('imei', 'like', $term)->orWhere('numero_serie', 'like', $term))
                    ->orWhereHas('sale', fn ($s) => $s->where('reference', 'like', $term))
                    ->orWhereHas('customer', fn ($c) => $c->where('nom', 'like', $term)->orWhere('telephone', 'like', $term)));
            })
            ->when($state === 'active', fn ($q) => $q->active())
            ->when($state === 'expiree', fn ($q) => $q->expired())
            ->when($state === 'annulee', fn ($q) => $q->where('statut', Warranty::CANCELLED))
            ->when($state === 'reclamation', fn ($q) => $q->whereHas('claims', fn ($c) => $c->whereIn('statut', WarrantyClaim::OPEN_STATUSES)))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('vente_id'), fn ($q) => $q->where('vente_id', $request->integer('vente_id')))
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(WarrantyResource::collection($items));
    }

    public function show(Store $store, Warranty $warranty)
    {
        $this->authorize('view', [$warranty, $store]);

        return $this->success(new WarrantyResource($this->details($warranty)));
    }

    /** POST /garanties/{id}/reclamations — refusée si la garantie n'est plus active. */
    public function claim(Store $store, Warranty $warranty, Request $request)
    {
        $this->authorize('manage', [$warranty, $store]);
        $data = $request->validate(['description' => ['required', 'string', 'max:2000']]);

        try {
            $this->warranties->fileClaim($warranty, $data['description'], $request->user()?->id);
        } catch (WarrantyException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        }

        return $this->success(new WarrantyResource($this->details($warranty)), 'Réclamation enregistrée.', [], 201);
    }

    /** PUT /garanties/{id}/reclamations/{claim} — étape suivante et note. */
    public function followUp(Store $store, Warranty $warranty, WarrantyClaim $claim, Request $request)
    {
        $this->authorize('manage', [$warranty, $store]);
        abort_if($claim->garantie_id !== $warranty->id, 404);
        $data = $request->validate([
            'statut' => ['required', Rule::in(WarrantyClaim::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->warranties->followUp($claim, $data['statut'], $data['note'] ?? null, $data['resolution'] ?? null, $request->user()?->id);
        } catch (WarrantyException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        }

        return $this->success(new WarrantyResource($this->details($warranty)), 'Réclamation mise à jour.');
    }

    private function details(Warranty $warranty): Warranty
    {
        return $warranty->load(['sale', 'device', 'customer', 'claims' => fn ($q) => $q->latest('id')->with('followUps.createdBy')]);
    }
}
