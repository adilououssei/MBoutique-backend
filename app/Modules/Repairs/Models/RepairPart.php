<?php

namespace App\Modules\Repairs\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reparation_id', 'produit_id', 'nom_produit', 'quantite', 'prix_unitaire', 'montant', 'facturee'])]
#[Table('pieces_reparation')]
class RepairPart extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3', 'prix_unitaire' => 'decimal:2', 'montant' => 'decimal:2', 'facturee' => 'boolean'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
