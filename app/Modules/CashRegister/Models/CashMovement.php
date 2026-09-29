<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\CashRegister\Enums\CashMovementType;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\CashRegister\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only ledger entry. Never updated or deleted by any endpoint in
 * this module — a mistake is corrected with a new `adjustment` movement,
 * not an edit of this one. Only CashRegisterService creates these.
 */
#[Fillable(['session_caisse_id', 'type', 'montant', 'solde_avant', 'solde_apres', 'motif', 'reference_type', 'reference_id', 'cree_par_id'])]
#[Table('mouvements_caisse')]
class CashMovement extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'montant' => 'decimal:2',
            'solde_avant' => 'decimal:2',
            'solde_apres' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CashMovementFactory
    {
        return CashMovementFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'session_caisse_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
