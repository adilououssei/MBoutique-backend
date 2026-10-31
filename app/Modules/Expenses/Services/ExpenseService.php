<?php

namespace App\Modules\Expenses\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Expenses\Enums\ExpensePaymentMode;
use App\Modules\Expenses\Exceptions\CashRegisterNotOpenException;
use App\Modules\Expenses\Exceptions\ExpenseAlreadyCancelledException;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Sales\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des dépenses — docs/modules.md §Expenses. Passe par
 * CashRegisterService pour toute sortie/entrée d'argent, comme Suppliers.
 */
class ExpenseService
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    /**
     * @param  array{categorie: string, libelle: string, montant: string|float|int, mode: string, caisse_id?: int|null, depense_le?: string|null, note?: string|null, cle_idempotence?: string|null}  $data
     */
    public function record(array $data, ?int $userId): Expense
    {
        $key = $data['cle_idempotence'] ?? null;
        if ($key !== null && ($existing = Expense::query()->where('cle_idempotence', $key)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($data, $userId, $key) {
            $amount = Money::round((string) $data['montant']);
            $mode = ExpensePaymentMode::from($data['mode']);
            $session = $mode === ExpensePaymentMode::CashRegister ? $this->openSession((int) $data['caisse_id']) : null;

            try {
                $expense = Expense::create([
                    'categorie' => $data['categorie'],
                    'libelle' => $data['libelle'],
                    'montant' => $amount,
                    'mode' => $mode,
                    'moyen_paiement' => $data['moyen_paiement'] ?? null,
                    'session_caisse_id' => $session?->id,
                    'depense_le' => $data['depense_le'] ?? now(),
                    'note' => $data['note'] ?? null,
                    'statut' => Expense::VALID,
                    'cle_idempotence' => $key,
                    'cree_par_id' => $userId,
                ]);
            } catch (QueryException $e) {
                if ($key !== null && ($e->errorInfo[0] ?? null) === '23000') {
                    return Expense::query()->where('cle_idempotence', $key)->firstOrFail();
                }
                throw $e;
            }
            $expense->update(['reference' => sprintf('DEP-%s-%06d', now()->format('Ymd'), $expense->id)]);

            if ($session !== null) {
                $this->cash(fn () => $this->cashRegisters->recordExpense($session, $amount, $userId, $expense, "Dépense : {$expense->libelle}"));
            }

            return $expense;
        });
    }

    /**
     * Annule une dépense : payée en caisse, l'argent y revient (entrée
     * référencée, dans `caisse_id` ou la caisse d'origine). La ligne reste.
     */
    public function cancel(Expense $expense, string $reason, ?int $registerId, ?int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $registerId, $userId) {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->isCancelled()) {
                throw new ExpenseAlreadyCancelledException("La dépense {$expense->reference} est déjà annulée.");
            }

            if ($expense->mode === ExpensePaymentMode::CashRegister) {
                $session = $this->openSession($registerId ?? (int) CashRegisterSession::query()->whereKey($expense->session_caisse_id)->value('caisse_id'));
                $this->cash(fn () => $this->cashRegisters->recordReceipt($session, (string) $expense->montant, $userId, $expense, "Annulation de la dépense {$expense->reference} : {$reason}"));
            }

            $expense->update(['statut' => Expense::CANCELLED, 'annulee_le' => now(), 'annulee_par_id' => $userId, 'motif_annulation' => $reason]);

            return $expense;
        });
    }

    private function openSession(int $registerId): CashRegisterSession
    {
        $register = CashRegister::query()->findOrFail($registerId);
        if ($register->session_ouverte_id === null) {
            throw new CashRegisterNotOpenException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
        }

        return CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
    }

    private function cash(\Closure $movement): void
    {
        try {
            $movement();
        } catch (CashRegisterSessionClosedException $e) {
            throw new CashRegisterNotOpenException($e->getMessage(), previous: $e);
        }
    }
}
