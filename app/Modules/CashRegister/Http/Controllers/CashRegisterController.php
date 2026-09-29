<?php

namespace App\Modules\CashRegister\Http\Controllers;

use App\Modules\CashRegister\Http\Requests\CreateCashRegisterRequest;
use App\Modules\CashRegister\Http\Requests\UpdateCashRegisterRequest;
use App\Modules\CashRegister\Http\Resources\CashRegisterResource;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

/**
 * Plain CRUD on the till definition itself — no financial logic here,
 * so no CashRegisterService involved (that's reserved for
 * CashRegisterSessionController/CashMovementController). No destroy():
 * a register with history is deactivated (actif=false), never hard
 * deleted — see docs/cash-register.md §"Caisse inactive".
 */
class CashRegisterController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [CashRegister::class, $store]);

        $registers = CashRegister::query()
            ->when($request->filled('recherche'), fn ($q) => $q->where('nom', 'like', '%'.$request->string('recherche').'%'))
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(CashRegisterResource::collection($registers));
    }

    public function store(Store $store, CreateCashRegisterRequest $request)
    {
        $this->authorize('create', [CashRegister::class, $store]);

        $register = CashRegister::create($request->validated());

        return $this->success(new CashRegisterResource($register), 'Caisse créée avec succès.', [], 201);
    }

    public function show(Store $store, CashRegister $cashRegister)
    {
        $this->authorize('view', [$cashRegister, $store]);

        return $this->success(new CashRegisterResource($cashRegister));
    }

    public function update(Store $store, CashRegister $cashRegister, UpdateCashRegisterRequest $request)
    {
        $this->authorize('update', [$cashRegister, $store]);

        $cashRegister->update($request->validated());

        return $this->success(new CashRegisterResource($cashRegister), 'Caisse mise à jour.');
    }
}
