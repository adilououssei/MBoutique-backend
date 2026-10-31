<?php

namespace App\Modules\Repairs\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Repairs\Exceptions\RepairException;
use App\Modules\Repairs\Http\Resources\RepairResource;
use App\Modules\Repairs\Models\Repair;
use App\Modules\Repairs\Models\RepairLine;
use App\Modules\Repairs\Models\RepairPart;
use App\Modules\Repairs\Services\RepairService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RepairController extends ApiController
{
    private const RELATIONS = ['customer', 'technician', 'technicians', 'services', 'parts', 'payments.createdBy', 'followUps.createdBy', 'quote', 'deliveredBy'];

    public function __construct(private readonly RepairService $repairs) {}

    /** `statut` (ou `en_cours=1`), `recherche` (n°, IMEI, client, téléphone), `client_id`, `technicien_id`. */
    public function index(Store $store, Request $request)
    {
        $this->can($request, 'reparations.voir');
        $items = Repair::query()->with(['customer', 'technician', 'services', 'parts'])
            ->when($request->boolean('en_cours'), fn ($q) => $q->whereIn('statut', Repair::OPEN))
            // En retard : ouvert et date prévue dépassée.
            ->when($request->boolean('en_retard'), fn ($q) => $q->whereIn('statut', Repair::OPEN)->whereNotNull('prevu_le')->whereDate('prevu_le', '<', now()->toDateString()))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('technicien_id'), fn ($q) => $q->where(fn ($q) => $q->where('technicien_id', $request->integer('technicien_id'))
                ->orWhereHas('technicians', fn ($t) => $t->where('employes.id', $request->integer('technicien_id')))))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.trim((string) $request->string('recherche')).'%';
                $q->where(fn ($q) => $q->where('numero', 'like', $term)->orWhere('imei', 'like', $term)->orWhere('numero_serie', 'like', $term)
                    ->orWhere('nom_client', 'like', $term)->orWhere('telephone_client', 'like', $term)->orWhere('appareil', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('nom', 'like', $term)->orWhere('telephone', 'like', $term)));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(RepairResource::collection($items));
    }

    public function store(Store $store, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate([
            ...$this->rules(),
            'appareil' => ['required', 'string', 'max:255'],
            'panne' => ['required', 'string', 'max:2000'],
            'nouveau_client' => ['nullable', 'array'],
            'nouveau_client.nom' => ['required_with:nouveau_client', 'string', 'max:255'],
            'nouveau_client.telephone' => ['nullable', 'string', 'max:30'],
            ...$this->lineRules('prestations.*.'),
            'prestations' => ['nullable', 'array', 'max:50'],
        ]);

        return $this->success(new RepairResource($this->repairs->open($data, $request->user()?->id)->load(self::RELATIONS)), 'Réparation enregistrée.', [], 201);
    }

    public function show(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.voir');

        return $this->success(new RepairResource($repair->load(self::RELATIONS)));
    }

    public function update(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate([...$this->rules(), 'appareil' => ['sometimes', 'string', 'max:255'], 'panne' => ['sometimes', 'string', 'max:2000']]);

        return $this->guard(fn () => $this->success(new RepairResource($this->repairs->update($repair, $data)->load(self::RELATIONS)), 'Réparation mise à jour.'));
    }

    /** GET /reparations/resume — compteurs par état (atelier : nouveaux, en cours, en retard, prêts, livrés). */
    public function summary(Store $store, Request $request)
    {
        $this->can($request, 'reparations.voir');
        $byStatus = Repair::query()->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut');

        return $this->success([
            'par_statut' => $byStatus,
            'en_cours' => (int) Repair::query()->whereIn('statut', Repair::OPEN)->count(),
            'en_retard' => (int) Repair::query()->whereIn('statut', Repair::OPEN)->whereNotNull('prevu_le')->whereDate('prevu_le', '<', now()->toDateString())->count(),
        ]);
    }

    public function changeStatus(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate([
            'statut' => ['required', Rule::in(Repair::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            // Livraison : reste au compte du client, personne qui récupère, réception confirmée.
            'laisser_a_credit' => ['sometimes', 'boolean'],
            'remis_a' => ['nullable', 'string', 'max:255'],
            'reception_confirmee' => ['sometimes', 'boolean'],
        ]);

        return $this->guard(fn () => $this->success(new RepairResource($this->repairs->changeStatus($repair, $data['statut'], $data['note'] ?? null, $request->user()?->id, $data)->load(self::RELATIONS))));
    }

    /** POST /reparations/{id}/prestations — ligne facturée (prestation, main-d'œuvre, frais, remise). */
    public function addLine(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate($this->lineRules(''));

        return $this->guard(fn () => $this->success(new RepairResource($this->repairs->addLine($repair, $data)->refresh()->load(self::RELATIONS)), 'Prestation ajoutée.', [], 201));
    }

    public function removeLine(Store $store, Repair $repair, RepairLine $line, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        abort_if($line->reparation_id !== $repair->id, 404);

        return $this->guard(fn () => $this->success(new RepairResource($this->repairs->removeLine($repair, $line)->refresh()->load(self::RELATIONS)), 'Prestation retirée.'));
    }

    /** POST /reparations/{id}/photos — `image` (jpg, png, webp ≤ 5 Mo), 10 au plus. */
    public function addPhoto(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $photos = $repair->photos ?? [];
        if (count($photos) >= 10) {
            return $this->error('10 photos au plus par dossier.', [], 422, 'PHOTOS_MAX');
        }
        $file = $request->file('image');
        $photos[] = $file->storeAs("reparations/{$store->id}", Str::uuid().'.'.$file->extension(), 'public');
        $repair->forceFill(['photos' => $photos])->save();

        return $this->success(new RepairResource($repair->load(self::RELATIONS)), 'Photo ajoutée.', [], 201);
    }

    /** DELETE /reparations/{id}/photos/{index} */
    public function removePhoto(Store $store, Repair $repair, int $index, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $photos = $repair->photos ?? [];
        abort_unless(isset($photos[$index]), 404);
        Storage::disk('public')->delete($photos[$index]);
        array_splice($photos, $index, 1);
        $repair->forceFill(['photos' => $photos])->save();

        return $this->success(new RepairResource($repair->load(self::RELATIONS)), 'Photo retirée.');
    }

    /** @return array<string, array<int, mixed>> */
    private function lineRules(string $prefix): array
    {
        return [
            "{$prefix}type" => ['nullable', Rule::in(['prestation', 'materiau', 'main_oeuvre', 'frais', 'remise'])],
            "{$prefix}service_id" => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('services')],
            "{$prefix}libelle" => ['nullable', 'string', 'max:255'],
            "{$prefix}quantite" => ['nullable', 'numeric', 'gt:0'],
            "{$prefix}prix_unitaire" => ['nullable', 'numeric'],
        ];
    }

    public function addPart(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate([
            'produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'quantite' => ['required', 'numeric', 'gt:0'],
            'prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            // false : matériau déjà compris dans le prix (devis, forfait).
            'facturee' => ['sometimes', 'boolean'],
        ]);

        return $this->guard(function () use ($repair, $data, $request) {
            $this->repairs->addPart($repair, Product::query()->findOrFail($data['produit_id']), (string) $data['quantite'], isset($data['prix_unitaire']) ? (string) $data['prix_unitaire'] : null, $request->user()?->id, $data['facturee'] ?? true);

            return $this->success(new RepairResource($repair->refresh()->load(self::RELATIONS)), 'Pièce ajoutée.', [], 201);
        });
    }

    public function removePart(Store $store, Repair $repair, RepairPart $part, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        abort_if($part->reparation_id !== $repair->id, 404);

        return $this->guard(function () use ($repair, $part, $request) {
            $this->repairs->removePart($repair, $part, $request->user()?->id);

            return $this->success(new RepairResource($repair->refresh()->load(self::RELATIONS)), 'Pièce retirée.');
        });
    }

    public function pay(Store $store, Repair $repair, Request $request)
    {
        $this->can($request, 'reparations.gerer');
        $data = $request->validate([
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['especes', 'mobile_money', 'carte', 'virement'])],
            'caisse_id' => ['nullable', 'required_if:mode,especes', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ]);

        return $this->guard(fn () => $this->success(new RepairResource($this->repairs->pay($repair, (string) $data['montant'], $data['mode'], $data['caisse_id'] ?? null, $request->user()?->id)->load(self::RELATIONS)), 'Paiement enregistré.', [], 201));
    }

    private function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')],
            'nom_client' => ['nullable', 'string', 'max:255'],
            'telephone_client' => ['nullable', 'string', 'max:30'],
            'appareil_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('appareils')],
            'marque' => ['nullable', 'string', 'max:100'],
            'modele' => ['nullable', 'string', 'max:100'],
            'imei' => ['nullable', 'string', 'max:30'],
            'numero_serie' => ['nullable', 'string', 'max:100'],
            'etat_reception' => ['nullable', 'string', 'max:2000'],
            'diagnostic' => ['nullable', 'string', 'max:2000'],
            'technicien_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')],
            'techniciens' => ['nullable', 'array', 'max:10'],
            'techniciens.*' => ['integer', 'distinct', TenantScopedRules::existsInCurrentStore('employes')],
            // Bien confié : champs libres selon la spécialité (immatriculation, kilométrage, dimensions…).
            'bien' => ['nullable', 'array', 'max:20'],
            'bien.*' => ['nullable', 'string', 'max:255'],
            'cout_estime' => ['nullable', 'numeric', 'min:0'],
            'cout_final' => ['nullable', 'numeric', 'min:0'],
            'prevu_le' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function can(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }

    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (RepairException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_NON_INITIALISE');
        }
    }
}
