<?php

namespace App\Modules\CashRegister\Services;

use App\Modules\CashRegister\Enums\CashMovementType;
use App\Modules\CashRegister\Enums\CashRegisterSessionStatus;
use App\Modules\CashRegister\Exceptions\CashRegisterAlreadyOpenException;
use App\Modules\CashRegister\Exceptions\CashRegisterInactiveException;
use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place that ever writes a CashRegisterSession's financial
 * fields or creates a CashMovement — see docs/cash-register.md
 * §"Service central". Sales records a sale's cash intake via
 * recordSale(), never touches these tables directly (docs/cash-register.md
 * §"Intégration future avec Sales", now implemented — see docs/sales.md).
 */
class CashRegisterService
{
    public function openSession(
        CashRegister $register,
        string $openingAmount,
        ?int $userId,
        ?string $reason = null,
    ): CashRegisterSession {
        return DB::transaction(function () use ($register, $openingAmount, $userId, $reason) {
            $register = CashRegister::query()->whereKey($register->id)->lockForUpdate()->firstOrFail();

            if (! $register->actif) {
                throw new CashRegisterInactiveException("La caisse \"{$register->nom}\" est désactivée.");
            }

            if ($register->session_ouverte_id !== null) {
                throw new CashRegisterAlreadyOpenException("La caisse \"{$register->nom}\" a déjà une session ouverte.");
            }

            $session = CashRegisterSession::create([
                'caisse_id' => $register->id,
                'ouverte_par_id' => $userId,
                'ouverte_le' => now(),
                'montant_ouverture' => $openingAmount,
                'statut' => CashRegisterSessionStatus::Open,
            ]);

            CashMovement::create([
                'session_caisse_id' => $session->id,
                'type' => CashMovementType::Opening,
                'montant' => $openingAmount,
                'solde_avant' => '0.00',
                'solde_apres' => $openingAmount,
                'motif' => $reason,
                'cree_par_id' => $userId,
            ]);

            // Deliberately a direct property write, not update(): this
            // pointer is never client-fillable (not in CashRegister's
            // #[Fillable]) — see docs/cash-register.md §"Une seule session ouverte".
            $register->session_ouverte_id = $session->id;
            $register->save();

            return $session;
        });
    }

    public function cashIn(CashRegisterSession $session, string $amount, ?int $userId, ?string $reason = null): CashMovement
    {
        return $this->recordMovement($session, CashMovementType::CashIn, $amount, $userId, $reason);
    }

    public function cashOut(CashRegisterSession $session, string $amount, ?int $userId, ?string $reason = null): CashMovement
    {
        return $this->recordMovement($session, CashMovementType::CashOut, bcmul($amount, '-1', 2), $userId, $reason);
    }

    /** $signedAmount may be positive or negative — a correction can go either way. */
    public function adjust(CashRegisterSession $session, string $signedAmount, ?int $userId, string $reason): CashMovement
    {
        return $this->recordMovement($session, CashMovementType::Adjustment, $signedAmount, $userId, $reason);
    }

    /**
     * The cash intake of a completed sale — always a positive amount
     * (a sale is never a cash-out). Kept separate from cashIn() rather
     * than overloading it with a type parameter, so cashIn()'s public
     * contract stays exactly "a manual cash-in" — see docs/sales.md
     * §"Intégration CashRegister".
     */
    public function recordSale(CashRegisterSession $session, string $amount, ?int $userId, Model $reference): CashMovement
    {
        return $this->recordMovement($session, CashMovementType::Sale, $amount, $userId, null, $reference);
    }

    public function closeSession(
        CashRegisterSession $session,
        string $actualClosingAmount,
        ?int $userId,
        ?string $closingNote = null,
    ): CashRegisterSession {
        return DB::transaction(function () use ($session, $actualClosingAmount, $userId, $closingNote) {
            // Lock ordering (register, then session) matches
            // openSession()'s ordering, avoiding a deadlock between the
            // two under concurrent open/close attempts on the same register.
            $register = CashRegister::query()->whereKey($session->caisse_id)->lockForUpdate()->firstOrFail();
            $session = CashRegisterSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isOpen()) {
                throw new CashRegisterSessionClosedException('Cette session de caisse est déjà fermée.');
            }

            $expected = $this->currentBalance($session);
            $difference = bcsub($actualClosingAmount, $expected, 2);

            $session->update([
                'statut' => CashRegisterSessionStatus::Closed,
                'fermee_par_id' => $userId,
                'fermee_le' => now(),
                'montant_fermeture_attendu' => $expected,
                'montant_fermeture_reel' => $actualClosingAmount,
                'ecart' => $difference,
                'note_fermeture' => $closingNote,
            ]);

            $register->session_ouverte_id = null;
            $register->save();

            return $session;
        });
    }

    private function recordMovement(
        CashRegisterSession $session,
        CashMovementType $type,
        string $delta,
        ?int $userId,
        ?string $reason,
        ?Model $reference = null,
    ): CashMovement {
        return DB::transaction(function () use ($session, $type, $delta, $userId, $reason, $reference) {
            $session = CashRegisterSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isOpen()) {
                throw new CashRegisterSessionClosedException('Cette session de caisse est fermée.');
            }

            $before = $this->currentBalance($session);
            $after = bcadd($before, $delta, 2);

            if (bccomp($after, '0', 2) < 0) {
                throw InsufficientCashException::forSession($session, $before, $delta);
            }

            return CashMovement::create([
                'session_caisse_id' => $session->id,
                'type' => $type,
                'montant' => $delta,
                'solde_avant' => $before,
                'solde_apres' => $after,
                'motif' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'cree_par_id' => $userId,
            ]);
        });
    }

    /**
     * No cached running balance on CashRegisterSession, deliberately —
     * the schema the Phase 4.2 brief describes doesn't list one, and a
     * session's ledger always has at least one movement (Opening) by
     * the time this is ever called — see docs/cash-register.md §"Solde
     * de la session".
     */
    private function currentBalance(CashRegisterSession $session): string
    {
        return CashMovement::query()
            ->where('session_caisse_id', $session->id)
            ->latest('id')
            ->value('solde_apres') ?? '0.00';
    }
}
