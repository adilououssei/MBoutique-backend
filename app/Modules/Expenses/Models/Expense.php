<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Expenses\Enums\ExpenseCategory;
use App\Modules\Expenses\Enums\ExpensePaymentMode;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reference', 'categorie', 'libelle', 'montant', 'mode', 'moyen_paiement', 'session_caisse_id', 'depense_le', 'note', 'justificatif', 'statut', 'annulee_le', 'annulee_par_id', 'motif_annulation', 'cle_idempotence', 'cree_par_id'])]
#[Table('depenses')]
class Expense extends Model
{
    /** Moyens d'une dépense payée hors caisse. */
    public const PAYMENT_MEANS = ['especes', 'mobile_money', 'carte', 'virement', 'cheque'];

    use BelongsToStore;

    public const VALID = 'validee';

    public const CANCELLED = 'annulee';

    protected function casts(): array
    {
        return [
            'categorie' => ExpenseCategory::class,
            'mode' => ExpensePaymentMode::class,
            'montant' => 'decimal:2',
            'depense_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'session_caisse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function isCancelled(): bool
    {
        return $this->statut === self::CANCELLED;
    }
}
