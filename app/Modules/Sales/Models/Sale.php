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
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finalized sale — only ever created atomically by
 * App\Modules\Sales\Services\SaleService::checkout(). Never created,
 * updated, or deleted directly by a controller. See docs/sales.md.
 */
#[Fillable(['caisse_id', 'session_caisse_id', 'client_id', 'vendeur_id', 'reference', 'sous_total', 'montant_remise', 'montant_total', 'statut', 'mode_paiement', 'cle_idempotence', 'vendue_le'])]
#[Table('ventes')]
class Sale extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'statut' => SaleStatus::class,
            'mode_paiement' => PaymentMethod::class,
            'sous_total' => 'decimal:2',
            'montant_remise' => 'decimal:2',
            'montant_total' => 'decimal:2',
            'vendue_le' => 'datetime',
        ];
    }

    protected static function newFactory(): SaleFactory
    {
        return SaleFactory::new();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'caisse_id');
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'session_caisse_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendeur_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'vente_id');
    }
}
