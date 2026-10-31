<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Modules\Catalog\Enums\ProductCondition;
use App\Modules\Catalog\Models\Product;
use App\Modules\Devices\Enums\DeviceStatus;
use App\Modules\Devices\Exceptions\DeviceTrackingException;
use App\Modules\Devices\Http\Resources\DeviceResource;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Services\DeviceService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends ApiController
{
    public function __construct(private readonly DeviceService $devices) {}

    /** `recherche` (IMEI, n° de série), `produit_id`, `statut`, `client_id`. */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Device::class, $store]);

        $items = Device::query()
            ->with(['product', 'customer'])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.strtoupper(preg_replace('/\s+/', '', (string) $request->string('recherche'))).'%';
                $q->where(fn ($q) => $q->where('imei', 'like', $term)->orWhere('imei_2', 'like', $term)->orWhere('numero_serie', 'like', $term));
            })
            ->when($request->filled('produit_id'), fn ($q) => $q->where('produit_id', $request->integer('produit_id')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(DeviceResource::collection($items));
    }

    public function show(Store $store, Device $device)
    {
        $this->authorize('view', [$device, $store]);

        return $this->success(new DeviceResource($device->load(['product', 'sale', 'customer', 'events' => fn ($q) => $q->latest('id')->with('createdBy')])));
    }

    /** POST /appareils — identifier des unités (déjà en stock, ou nouvel arrivage avec `ajouter_au_stock`). */
    public function store(Store $store, Request $request)
    {
        $this->authorize('create', [Device::class, $store]);
        $data = $request->validate([
            'produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            'identifiants' => ['required', 'array', 'min:1', 'max:200'],
            'identifiants.*' => ['required', 'string', 'max:100'],
            'ajouter_au_stock' => ['sometimes', 'boolean'],
            'cout_achat' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $devices = $this->devices->register(
                Product::query()->findOrFail($data['produit_id']),
                $data['identifiants'],
                (bool) ($data['ajouter_au_stock'] ?? false),
                isset($data['cout_achat']) ? (string) $data['cout_achat'] : null,
                $data['note'] ?? null,
                $request->user()?->id,
            );

            return $this->success(DeviceResource::collection($devices->each->load('product')), count($devices).' appareil(s) enregistré(s).', [], 201);
        });
    }

    /** Corriger les identifiants, l'état ou la note (pas le statut : voir `statut`). */
    public function update(Store $store, Device $device, Request $request)
    {
        $this->authorize('update', [$device, $store]);
        $data = $request->validate([
            'imei' => ['sometimes', 'nullable', 'digits:15', Rule::unique('appareils', 'imei')->where('boutique_id', $store->id)->ignore($device->id)],
            'imei_2' => ['sometimes', 'nullable', 'digits:15'],
            'numero_serie' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('appareils', 'numero_serie')->where('boutique_id', $store->id)->ignore($device->id)],
            'etat' => ['sometimes', 'nullable', Rule::enum(ProductCondition::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if ((array_key_exists('imei', $data) || array_key_exists('numero_serie', $data))
            && blank($data['imei'] ?? $device->imei) && blank($data['numero_serie'] ?? $device->numero_serie)) {
            return $this->error('Un appareil garde au moins un IMEI ou un numéro de série.', [], 422, 'APPAREILS_REQUIS');
        }

        $device->update($data);
        $this->devices->log($device, 'modification', 'Fiche modifiée', null, $request->user()?->id);

        return $this->show($store, $device->refresh());
    }

    /** POST /appareils/{id}/statut — défectueux, perdu (sortent du stock) ou en_stock (y reviennent). */
    public function changeStatus(Store $store, Device $device, Request $request)
    {
        $this->authorize('update', [$device, $store]);
        $data = $request->validate([
            'statut' => ['required', Rule::in([DeviceStatus::InStock->value, DeviceStatus::Defective->value, DeviceStatus::Lost->value])],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->guard(function () use ($store, $device, $data, $request) {
            $this->devices->changeStatus($device, DeviceStatus::from($data['statut']), $data['motif'] ?? null, $request->user()?->id);

            return $this->show($store, $device->refresh());
        });
    }

    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (DeviceTrackingException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        }
    }
}
