<?php

namespace App\Modules\CashRegister\Models;

use App\Models\User;
use App\Modules\CashRegister\Enums\CashRegisterSessionStatus;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\CashRegister\CashRegisterSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A period of use of a CashRegister. `expected_closing_amount` is always
 * computed by CashRegisterService from the ledger at close time — never
 * accepted from the client. See docs/cash-register.md.
 */
#[Fillable(['cash_register_id', 'opened_by_user_id', 'closed_by_user_id', 'opened_at', 'closed_at', 'opening_amount', 'expected_closing_amount', 'actual_closing_amount', 'difference', 'status', 'closing_note'])]
class CashRegisterSession extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CashRegisterSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_amount' => 'decimal:2',
            'expected_closing_amount' => 'decimal:2',
            'actual_closing_amount' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CashRegisterSessionFactory
    {
        return CashRegisterSessionFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashRegisterSessionStatus::Open;
    }
}
