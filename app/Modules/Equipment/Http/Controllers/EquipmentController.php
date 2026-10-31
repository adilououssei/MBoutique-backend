<?php

namespace App\Modules\Equipment\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Equipment\Enums\EquipmentType;
use App\Modules\Equipment\Enums\InterventionType;
use App\Modules\Equipment\Http\Requests\EquipmentRequest;
use App\Modules\Equipment\Http\Requests\InterventionRequest;
use App\Modules\Equipment\Http\Resources\EquipmentResource;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Services\EquipmentService;
use App\Modules\Expenses\Exceptions\CashRegisterNotOpenException;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class EquipmentController extends ApiController
{
    public function __construct(private readonly EquipmentService $equipment) {}

    /** `type`, `statut`, `stockage=1` (espaces de conservation seulement), `actif`. */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Equipment::class, $store]);

        $items = Equipment::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->boolean('stockage'), fn ($q) => $q->whereIn('type', EquipmentType::storageValues()))
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 50), 100));

        return $this->success(EquipmentResource::collection($items));
    }

    public function store(Store $store, EquipmentRequest $request)
    {
        $this->authorize('create', [Equipment::class, $store]);

        return $this->success(new EquipmentResource($this->equipment->create($request->validated())), 'Équipement enregistré.', [], 201);
    }

    public function show(Store $store, Equipment $equipment)
    {
        $this->authorize('view', [$equipment, $store]);

        return $this->success(new EquipmentResource($equipment->load(['interventions' => fn ($q) => $q->latest('survenue_le')->with('createdBy')])));
    }

    public function update(Store $store, Equipment $equipment, EquipmentRequest $request)
    {
        $this->authorize('update', [$equipment, $store]);
        $equipment->update($request->validated());

        return $this->success(new EquipmentResource($equipment->refresh()), 'Équipement mis à jour.');
    }

    public function destroy(Store $store, Equipment $equipment)
    {
        $this->authorize('delete', [$equipment, $store]);
        $equipment->delete();

        return $this->success(null, 'Équipement supprimé.');
    }

    /** POST /equipements/{id}/interventions — entretien, panne ou réparation. */
    public function intervene(Store $store, Equipment $equipment, InterventionRequest $request)
    {
        $this->authorize('intervene', [$equipment, $store, InterventionType::from($request->validated('type'))]);

        try {
            $this->equipment->recordIntervention($store, $equipment, $request->validated(), $request->user()?->id);
        } catch (CashRegisterNotOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }

        return $this->success(new EquipmentResource($equipment->refresh()->load(['interventions' => fn ($q) => $q->latest('survenue_le')->with('createdBy')])), 'Intervention enregistrée.', [], 201);
    }
}
