<?php

namespace App\Modules\CashRegister\Http\Controllers;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\CashRegister\Http\Requests\AdjustCashRequest;
use App\Modules\CashRegister\Http\Requests\CashInRequest;
use App\Modules\CashRegister\Http\Requests\CashOutRequest;
use App\Modules\CashRegister\Http\Resources\CashMovementResource;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Closure;
use Illuminate\Http\Request;

class CashMovementController extends ApiController
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    public function index(Store $store, CashRegister $cashRegister, CashRegisterSession $session, Request $request)
    {
        $this->authorize('viewAny', [CashMovement::class, $store]);

        $movements = CashMovement::query()
            ->where('cash_register_session_id', $session->id)
            ->with('createdBy')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(CashMovementResource::collection($movements));
    }

    public function cashIn(Store $store, CashRegister $cashRegister, CashRegisterSession $session, CashInRequest $request)
    {
        $this->authorize('create', [CashMovement::class, $store]);

        return $this->record(fn () => $this->cashRegisters->cashIn(
            $session, (string) $request->input('amount'), $request->user()?->id, $request->input('reason'),
        ));
    }

    public function cashOut(Store $store, CashRegister $cashRegister, CashRegisterSession $session, CashOutRequest $request)
    {
        $this->authorize('create', [CashMovement::class, $store]);

        return $this->record(fn () => $this->cashRegisters->cashOut(
            $session, (string) $request->input('amount'), $request->user()?->id, $request->input('reason'),
        ));
    }

    public function adjust(Store $store, CashRegister $cashRegister, CashRegisterSession $session, AdjustCashRequest $request)
    {
        $this->authorize('create', [CashMovement::class, $store]);

        return $this->record(fn () => $this->cashRegisters->adjust(
            $session, (string) $request->input('amount'), $request->user()?->id, (string) $request->input('reason'),
        ));
    }

    private function record(Closure $operation)
    {
        try {
            $movement = $operation();
        } catch (CashRegisterSessionClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'CASH_REGISTER_SESSION_CLOSED');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'INSUFFICIENT_CASH');
        }

        return $this->success(new CashMovementResource($movement), 'Mouvement de caisse enregistré.', [], 201);
    }
}
