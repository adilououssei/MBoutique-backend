<?php

namespace App\Modules\CashRegister\Http\Controllers;

use App\Modules\CashRegister\Exceptions\CashRegisterAlreadyOpenException;
use App\Modules\CashRegister\Exceptions\CashRegisterInactiveException;
use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Http\Requests\CloseCashRegisterSessionRequest;
use App\Modules\CashRegister\Http\Requests\OpenCashRegisterSessionRequest;
use App\Modules\CashRegister\Http\Resources\CashRegisterSessionResource;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

class CashRegisterSessionController extends ApiController
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    /** History — every session (open or closed) this register has had. */
    public function index(Store $store, CashRegister $cashRegister, Request $request)
    {
        $this->authorize('viewAny', [CashRegisterSession::class, $store]);

        $sessions = CashRegisterSession::query()
            ->where('cash_register_id', $cashRegister->id)
            ->with(['openedBy', 'closedBy'])
            ->latest('opened_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return $this->success(CashRegisterSessionResource::collection($sessions));
    }

    public function current(Store $store, CashRegister $cashRegister)
    {
        $this->authorize('viewAny', [CashRegisterSession::class, $store]);

        if ($cashRegister->open_session_id === null) {
            return $this->success(null, 'Aucune session ouverte pour cette caisse.');
        }

        $session = CashRegisterSession::query()->with(['openedBy'])->findOrFail($cashRegister->open_session_id);

        return $this->success(new CashRegisterSessionResource($session));
    }

    public function open(Store $store, CashRegister $cashRegister, OpenCashRegisterSessionRequest $request)
    {
        $this->authorize('open', [CashRegisterSession::class, $store]);

        try {
            $session = $this->cashRegisters->openSession(
                $cashRegister,
                (string) $request->input('opening_amount'),
                $request->user()?->id,
                $request->input('reason'),
            );
        } catch (CashRegisterAlreadyOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'CASH_REGISTER_ALREADY_OPEN');
        } catch (CashRegisterInactiveException $e) {
            return $this->error($e->getMessage(), [], 422, 'CASH_REGISTER_INACTIVE');
        }

        return $this->success(new CashRegisterSessionResource($session), 'Session de caisse ouverte.', [], 201);
    }

    public function close(Store $store, CashRegister $cashRegister, CashRegisterSession $session, CloseCashRegisterSessionRequest $request)
    {
        $this->authorize('close', [CashRegisterSession::class, $store]);

        try {
            $session = $this->cashRegisters->closeSession(
                $session,
                (string) $request->input('actual_closing_amount'),
                $request->user()?->id,
                $request->input('closing_note'),
            );
        } catch (CashRegisterSessionClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'CASH_REGISTER_SESSION_CLOSED');
        }

        return $this->success(new CashRegisterSessionResource($session), 'Session de caisse fermée.');
    }
}
