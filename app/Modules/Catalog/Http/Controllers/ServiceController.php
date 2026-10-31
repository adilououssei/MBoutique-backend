<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\CreateServiceRequest;
use App\Modules\Catalog\Http\Requests\UpdateServiceRequest;
use App\Modules\Catalog\Http\Resources\ServiceResource;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceConsumableService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceController extends ApiController
{
    public function __construct(private readonly ServiceConsumableService $consumables) {}

    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Service::class, $store]);

        $services = Service::query()
            ->with('category')
            ->when($request->filled('recherche'), fn ($q) => $q->where('nom', 'like', '%'.$request->string('recherche').'%'))
            ->when($request->filled('categorie_id'), fn ($q) => $q->where('categorie_id', $request->integer('categorie_id')))
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(ServiceResource::collection($services));
    }

    public function store(Store $store, CreateServiceRequest $request)
    {
        $this->authorize('create', [Service::class, $store]);

        $service = DB::transaction(function () use ($request) {
            $service = Service::create(Arr::except($request->validated(), ['consommables', 'composants', 'tarifs_articles']));
            $this->syncArticlePrices($service, $request->validated('tarifs_articles'));
            if ($request->has('consommables')) {
                $this->consumables->save($service, $request->validated('consommables'));
            }
            $this->syncComponents($service, $request->validated('composants'));

            return $service;
        });

        return $this->success(new ServiceResource($this->detailed($service)), 'Service créé avec succès.', [], 201);
    }

    public function show(Store $store, Service $service)
    {
        $this->authorize('view', [$service, $store]);

        return $this->success(new ServiceResource($this->detailed($service)));
    }

    public function update(Store $store, Service $service, UpdateServiceRequest $request)
    {
        $this->authorize('update', [$service, $store]);

        DB::transaction(function () use ($request, $service) {
            $service->update(Arr::except($request->validated(), ['consommables', 'composants', 'tarifs_articles']));
            $this->syncArticlePrices($service, $request->validated('tarifs_articles'));
            if ($request->has('consommables')) {
                $this->consumables->save($service, $request->validated('consommables'));
            }
            if ($request->has('composants') || ! $service->est_formule) {
                $this->syncComponents($service, $service->est_formule ? $request->validated('composants') : []);
            }
        });

        return $this->success(new ServiceResource($this->detailed($service)), 'Service mis à jour.');
    }

    /** Fiche complète : catégorie, consommables et leur coût, composants d'une formule, employés habilités. */
    private function detailed(Service $service): Service
    {
        $service->load(['category', 'consumables.product', 'components', 'articlePrices']);
        $hasConsumables = $service->consumables->isNotEmpty() || ($service->est_formule && $this->consumables->consumption($service, '1') !== []);
        $service->setAttribute('cout_consommables', $hasConsumables ? $this->consumables->cost($service) : null);
        // Habilités : explicitement pour cette prestation (les employés sans habilitation font tout).
        $service->setAttribute('employes_habilites', Employee::query()->whereHas('services', fn ($q) => $q->whereKey($service->id))->orderBy('nom')->get(['id', 'nom'])->map(fn ($e) => ['id' => $e->id, 'nom' => $e->nom])->all());
        $service->setAttribute('employes_sans_restriction', Employee::query()->where('actif', true)->whereDoesntHave('services')->orderBy('nom')->pluck('nom')->all());

        return $service;
    }

    /** @param  array<int, array{type_article: string, prix: string|float|int}>|null  $prices */
    private function syncArticlePrices(Service $service, ?array $prices): void
    {
        if ($prices === null) {
            return;
        }
        $service->articlePrices()->delete();
        foreach ($prices as $price) {
            $service->articlePrices()->create(['type_article' => trim($price['type_article']), 'prix' => (string) $price['prix']]);
        }
    }

    /** @param  array<int, int>|null  $ids */
    private function syncComponents(Service $service, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }
        if (in_array($service->id, $ids, true)) {
            throw ValidationException::withMessages(['composants' => 'Une formule ne peut pas se contenir elle-même.']);
        }
        $service->components()->sync(collect(array_values($ids))->mapWithKeys(fn ($id, $i) => [$id => ['boutique_id' => $service->boutique_id, 'ordre' => $i]])->all());
    }

    public function destroy(Store $store, Service $service)
    {
        $this->authorize('delete', [$service, $store]);

        $service->delete();

        return $this->success(null, 'Service supprimé.');
    }
}
