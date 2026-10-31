<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['numero', 'vente_id', 'client_id', 'motif', 'decision', 'etat_produits', 'statut_verification', 'verifie_par_id', 'verifie_le', 'note_verification', 'montant', 'montant_rendu', 'mode_remboursement', 'session_caisse_id', 'vente_echange_id', 'cree_par_id'])]
#[Table('retours')]
class SaleReturn extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['montant' => 'decimal:2'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'vente_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class, 'retour_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
