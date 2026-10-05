<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Http\Requests\TableRequest;
use App\Modules\Orders\Http\Resources\DiningTableResource;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

/** Plan de salle. Pas de DELETE : une table se désactive (historique des commandes). */
class DiningTableController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [DiningTable::class, $store]);

        $tables = DiningTable::query()
            ->with('openOrder.items')
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->get();

        return $this->success(DiningTableResource::collection($tables));
    }

    public function store(Store $store, TableRequest $request)
    {
        $this->authorize('create', [DiningTable::class, $store]);

        return $this->success(new DiningTableResource(DiningTable::create($request->validated())), 'Table ajoutée.', [], 201);
    }

    public function update(Store $store, DiningTable $diningTable, TableRequest $request)
    {
        $this->authorize('update', [$diningTable, $store]);

        $diningTable->update($request->validated());

        return $this->success(new DiningTableResource($diningTable->load('openOrder.items')), 'Table mise à jour.');
    }
}
