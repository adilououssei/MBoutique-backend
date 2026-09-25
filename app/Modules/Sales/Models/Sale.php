<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Sales\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finalized sale — only ever created atomically by
 * App\Modules\Sales\Services\SaleService::checkout(). Never created,
 * updated, or deleted directly by a controller. See docs/sales.md.
 */
#[Fillable(['cash_register_id', 'cash_register_session_id', 'customer_id', 'sold_by_user_id', 'reference', 'subtotal', 'discount_amount', 'total_amount', 'status', 'payment_method', 'idempotency_key', 'sold_at'])]
class Sale extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SaleFactory
    {
        return SaleFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
