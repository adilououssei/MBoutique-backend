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
            ->where('caisse_id', $cashRegister->id)
            ->with(['openedBy', 'closedBy'])
            ->latest('ouverte_le')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        return $this->success(CashRegisterSessionResource::collection($sessions));
    }

    public function current(Store $store, CashRegister $cashRegister)
    {
        $this->authorize('viewAny', [CashRegisterSession::class, $store]);

        if ($cashRegister->session_ouverte_id === null) {
            return $this->success(null, 'Aucune session ouverte pour cette caisse.');
        }

        $session = CashRegisterSession::query()->with(['openedBy'])->findOrFail($cashRegister->session_ouverte_id);

        return $this->success(new CashRegisterSessionResource($session));
    }

    public function open(Store $store, CashRegister $cashRegister, OpenCashRegisterSessionRequest $request)
    {
        $this->authorize('open', [CashRegisterSession::class, $store]);

        try {
            $session = $this->cashRegisters->openSession(
                $cashRegister,
                (string) $request->input('montant_ouverture'),
                $request->user()?->id,
                $request->input('motif'),
            );
        } catch (CashRegisterAlreadyOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'CAISSE_DEJA_OUVERTE');
        } catch (CashRegisterInactiveException $e) {
            return $this->error($e->getMessage(), [], 422, 'CAISSE_INACTIVE');
        }

        return $this->success(new CashRegisterSessionResource($session), 'Session de caisse ouverte.', [], 201);
    }

    public function close(Store $store, CashRegister $cashRegister, CashRegisterSession $session, CloseCashRegisterSessionRequest $request)
    {
        $this->authorize('close', [CashRegisterSession::class, $store]);

        try {
            $session = $this->cashRegisters->closeSession(
                $session,
                (string) $request->input('montant_fermeture_reel'),
                $request->user()?->id,
                $request->input('note_fermeture'),
            );
        } catch (CashRegisterSessionClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'SESSION_CAISSE_FERMEE');
        }

        return $this->success(new CashRegisterSessionResource($session), 'Session de caisse fermée.');
    }
}
