<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\CashRegister\Enums\CashMovementType;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\CashRegister\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only ledger entry. Never updated or deleted by any endpoint in
 * this module — a mistake is corrected with a new `adjustment` movement,
 * not an edit of this one. Only CashRegisterService creates these.
 */
#[Fillable(['cash_register_session_id', 'type', 'amount', 'balance_before', 'balance_after', 'reason', 'reference_type', 'reference_id', 'created_by_user_id'])]
class CashMovement extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CashMovementFactory
    {
        return CashMovementFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
