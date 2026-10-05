<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Une ligne du compte client (append-only). Montant signé : + dette, − paiement/annulation. */
#[Fillable(['client_id', 'type', 'montant', 'mode', 'session_caisse_id', 'reference_type', 'reference_id', 'note', 'cree_par_id'])]
#[Table('mouvements_compte_client')]
class CustomerAccountEntry extends Model
{
    use BelongsToStore;

    public const CREDIT_SALE = 'vente_credit';

    public const PAYMENT = 'paiement';

    public const SALE_CANCELLED = 'annulation_vente';

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
