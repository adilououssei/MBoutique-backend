<?php

namespace App\Modules\CashRegister\Http\Controllers;

use App\Modules\CashRegister\Http\Requests\CreateCashRegisterRequest;
use App\Modules\CashRegister\Http\Requests\UpdateCashRegisterRequest;
use App\Modules\CashRegister\Http\Resources\CashRegisterResource;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Services\CashRegisterJournal;
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
            ->with('createdBy')
            ->when($request->boolean('mes_caisses'), fn ($q) => $q->where('cree_par_id', $request->user()->id))
            ->when($request->filled('recherche'), fn ($q) => $q->where('nom', 'like', '%'.$request->string('recherche').'%'))
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(CashRegisterResource::collection($registers));
    }

    public function store(Store $store, CreateCashRegisterRequest $request)
    {
        $this->authorize('create', [CashRegister::class, $store]);

        // Une seule caisse active par personne et par boutique.
        $mine = CashRegister::query()->where('cree_par_id', $request->user()->id)->where('actif', true)->first();
        if ($mine !== null) {
            return $this->error("Vous avez déjà une caisse dans cette boutique : « {$mine->nom} ». Chaque personne n'a qu'une caisse.", [], 422, 'CAISSE_DEJA_ATTRIBUEE');
        }

        $register = CashRegister::create($request->validated());
        $register->forceFill(['cree_par_id' => $request->user()->id])->save();
        $register->load('createdBy');

        return $this->success(new CashRegisterResource($register), 'Caisse créée avec succès.', [], 201);
    }

    public function show(Store $store, CashRegister $cashRegister)
    {
        $this->authorize('view', [$cashRegister, $store]);

        return $this->success(new CashRegisterResource($cashRegister->load('createdBy')));
    }

    /**
     * GET caisses/{id}/journal?jours=30 — ventes de la caisse regroupées par
     * jour (fuseau de la boutique) : la journée en cours repart de zéro, les
     * totaux des jours précédents restent consultables.
     */
    public function journal(Store $store, CashRegister $cashRegister, Request $request, CashRegisterJournal $journal)
    {
        $this->authorize('view', [$cashRegister, $store]);

        $days = max(1, min((int) $request->integer('jours', 30), 90));

        return $this->success($journal->build($store, $cashRegister, $days));
    }

    public function update(Store $store, CashRegister $cashRegister, UpdateCashRegisterRequest $request)
    {
        $this->authorize('update', [$cashRegister, $store]);

        // Réactiver une ancienne caisse ne doit pas en donner deux à la même personne.
        if ($request->boolean('actif') && ! $cashRegister->actif && $cashRegister->cree_par_id !== null
            && CashRegister::query()->where('cree_par_id', $cashRegister->cree_par_id)->where('actif', true)->exists()) {
            return $this->error('Vous avez déjà une autre caisse active : désactivez-la d’abord.', [], 422, 'CAISSE_DEJA_ATTRIBUEE');
        }

        $cashRegister->update($request->validated());

        return $this->success(new CashRegisterResource($cashRegister->load('createdBy')), 'Caisse mise à jour.');
    }
}
