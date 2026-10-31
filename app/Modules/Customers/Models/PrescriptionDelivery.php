<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ordonnance_id', 'ligne_ordonnance_id', 'vente_id', 'ligne_vente_id', 'quantite', 'annulee', 'cree_par_id'])]
#[Table('delivrances_ordonnance')]
class PrescriptionDelivery extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3', 'annulee' => 'boolean'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
