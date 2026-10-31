<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Exceptions\PrescriptionException;
use App\Modules\Customers\Models\Prescription;
use App\Modules\Customers\Services\PrescriptionService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ordonnances (feature `ordonnances`). Données de santé : lecture
 * `ordonnances.voir`, écriture `ordonnances.gerer`. Aucune aide au
 * diagnostic, aucune substitution proposée.
 */
class PrescriptionController extends ApiController
{
    private const RELATIONS = ['customer', 'lines.product', 'deliveries.createdBy', 'createdBy'];

    public function __construct(private readonly PrescriptionService $prescriptions) {}

    /** `statut` (a_delivrer = en attente ou partielle), `client_id`, `recherche`. */
    public function index(Store $store, Request $request)
    {
        $this->can($request, 'ordonnances.voir');
        $items = Prescription::query()->with(['customer', 'lines'])
            ->when($request->string('statut')->value() === 'a_delivrer', fn ($q) => $q->whereIn('statut', ['en_attente', 'partielle']))
            ->when($request->filled('statut') && $request->string('statut')->value() !== 'a_delivrer', fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.trim((string) $request->string('recherche')).'%';
                $q->where(fn ($q) => $q->where('numero', 'like', $term)->orWhere('nom_patient', 'like', $term)->orWhere('prescripteur', 'like', $term));
            })
            ->latest('id')->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success($items->through(fn (Prescription $p) => $this->present($p)));
    }

    public function show(Store $store, Prescription $prescription, Request $request)
    {
        $this->can($request, 'ordonnances.voir');

        return $this->success($this->present($prescription->load(self::RELATIONS)));
    }

    public function store(Store $store, Request $request)
    {
        $this->can($request, 'ordonnances.gerer');

        return $this->guard(fn () => $this->success($this->present($this->prescriptions->save(null, $this->validated($request, true), $request->user()?->id)->load(self::RELATIONS)), 'Ordonnance enregistrée.', [], 201));
    }

    public function update(Store $store, Prescription $prescription, Request $request)
    {
        $this->can($request, 'ordonnances.gerer');

        return $this->guard(fn () => $this->success($this->present($this->prescriptions->save($prescription, $this->validated($request, false), $request->user()?->id)->load(self::RELATIONS)), 'Ordonnance mise à jour.'));
    }

    /** POST /ordonnances/{id}/annuler — sans effet sur les délivrances déjà faites. */
    public function cancel(Store $store, Prescription $prescription, Request $request)
    {
        $this->can($request, 'ordonnances.gerer');
        $prescription->update(['statut' => 'annulee']);

        return $this->success($this->present($prescription->load(self::RELATIONS)), 'Ordonnance annulée.');
    }

    /** POST /ordonnances/{id}/photo — `image` (jpg, png, webp ≤ 5 Mo). */
    public function photo(Store $store, Prescription $prescription, Request $request)
    {
        $this->can($request, 'ordonnances.gerer');
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        if ($prescription->photo) {
            Storage::disk('local')->delete($prescription->photo);
        }
        // Disque privé : la photo n'est servie que par l'API, aux personnes habilitées.
        $file = $request->file('image');
        $prescription->update(['photo' => $file->storeAs("ordonnances/{$store->id}", Str::uuid().'.'.$file->extension(), 'local')]);

        return $this->success($this->present($prescription->load(self::RELATIONS)), 'Photo enregistrée.');
    }

    /** GET /ordonnances/{id}/photo — le fichier, avec les mêmes droits que l'ordonnance. */
    public function showPhoto(Store $store, Prescription $prescription, Request $request)
    {
        $this->can($request, 'ordonnances.voir');
        abort_unless($prescription->photo && Storage::disk('local')->exists($prescription->photo), 404);

        return Storage::disk('local')->response($prescription->photo);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')],
            'nom_patient' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'prescripteur' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'etablissement' => ['nullable', 'string', 'max:255'],
            'prescrite_le' => [$creating ? 'required' : 'sometimes', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lignes' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            'lignes.*.id' => ['nullable', 'integer'],
            'lignes.*.produit_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'lignes.*.libelle' => ['nullable', 'required_without:lignes.*.produit_id', 'string', 'max:255'],
            'lignes.*.posologie' => ['nullable', 'string', 'max:500'],
            'lignes.*.quantite_prescrite' => ['required', 'numeric', 'gt:0'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Prescription $p): array
    {
        return [
            'id' => $p->id,
            'numero' => $p->numero,
            'client' => $p->relationLoaded('customer') && $p->customer ? ['id' => $p->customer->id, 'nom' => $p->customer->nom] : null,
            'nom_patient' => $p->nom_patient,
            'prescripteur' => $p->prescripteur,
            'etablissement' => $p->etablissement,
            'prescrite_le' => $p->prescrite_le?->toDateString(),
            'statut' => $p->statut,
            'note' => $p->note,
            'a_photo' => $p->photo !== null,
            'lignes' => $p->relationLoaded('lines') ? $p->lines->map(fn ($l) => [
                'id' => $l->id, 'produit_id' => $l->produit_id, 'libelle' => $l->libelle, 'posologie' => $l->posologie,
                'quantite_prescrite' => $l->quantite_prescrite, 'quantite_delivree' => $l->quantite_delivree, 'reste' => $l->remaining(),
            ])->values() : [],
            'delivrances' => $p->relationLoaded('deliveries') ? $p->deliveries->map(fn ($d) => [
                'vente_id' => $d->vente_id, 'ligne_ordonnance_id' => $d->ligne_ordonnance_id, 'quantite' => $d->quantite, 'annulee' => $d->annulee, 'par' => $d->createdBy?->nom, 'le' => $d->created_at,
            ])->values() : [],
            'cree_le' => $p->created_at,
        ];
    }

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    private function guard(\Closure $operation)
    {
        try {
            return $operation();
        } catch (PrescriptionException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        }
    }
}
