<?php

namespace App\Modules\CashRegister\Models;

use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\CashRegister\CashRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The permanent till — not to be confused with CashRegisterSession, a
 * period of use of it. See docs/cash-register.md §"CashRegister ≠
 * CashRegisterSession". `open_session_id` is the single source of truth
 * for "is this register currently open, and with which session" —
 * written only by CashRegisterService, under lockForUpdate().
 */
#[Fillable(['name', 'code', 'is_active'])]
class CashRegister extends Model
{
    use BelongsToStore, HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function newFactory(): CashRegisterFactory
    {
        return CashRegisterFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    public function isOpen(): bool
    {
        return $this->open_session_id !== null;
    }
}
