<?php

namespace App\Modules\Employees\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Employees\Enums\EmployeePaymentMode;
use App\Modules\Employees\Enums\EmployeePaymentType;
use App\Modules\Employees\Exceptions\CashRegisterNotOpenException;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeePayment;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des paiements au personnel — docs/modules.md
 * §Employees. Un paiement « caisse » sort de la session ouverte via
 * CashRegisterService::recordExpense() (jamais d'écriture directe dans
 * mouvements_caisse) ; tout-ou-rien en transaction.
 */
class EmployeePaymentService
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    /**
     * @param  array{type: string, montant: string|float|int, mode: string, caisse_id?: int|null, periode?: string|null, note?: string|null}  $data
     */
    public function pay(Employee $employee, array $data, ?int $userId): EmployeePayment
    {
        return DB::transaction(function () use ($employee, $data, $userId) {
            $type = EmployeePaymentType::from($data['type']);
            $mode = EmployeePaymentMode::from($data['mode']);
            $amount = Money::round((string) $data['montant']);
            $sessionId = null;

            $payment = EmployeePayment::create([
                'employe_id' => $employee->id,
                'type' => $type,
                'montant' => $amount,
                'periode' => $data['periode'] ?? null,
                'mode' => $mode,
                'note' => $data['note'] ?? null,
                'paye_le' => now(),
                'cree_par_id' => $userId,
            ]);

            if ($mode === EmployeePaymentMode::CashRegister) {
                $register = CashRegister::query()->findOrFail($data['caisse_id']);
                if ($register->session_ouverte_id === null) {
                    throw new CashRegisterNotOpenException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
                }
                $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
                $label = ['salaire' => 'Salaire', 'avance' => 'Avance', 'prime' => 'Prime'][$type->value];

                try {
                    $this->cashRegisters->recordExpense($session, $amount, $userId, $payment, trim("{$label} — {$employee->nom} ".($data['periode'] ?? '')));
                } catch (CashRegisterSessionClosedException $e) {
                    throw new CashRegisterNotOpenException($e->getMessage(), previous: $e);
                }
                $sessionId = $session->id;
                $payment->update(['session_caisse_id' => $sessionId]);
            }

            return $payment;
        });
    }
}
