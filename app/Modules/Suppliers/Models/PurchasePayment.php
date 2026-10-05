<?php

namespace App\Modules\Suppliers\Models;

use App\Models\User;
use App\Modules\Suppliers\Enums\PurchasePaymentMode;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['achat_id', 'montant', 'mode', 'session_caisse_id', 'note', 'paye_le', 'cree_par_id'])]
#[Table('paiements_achat')]
class PurchasePayment extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'mode' => PurchasePaymentMode::class,
            'paye_le' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'achat_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
