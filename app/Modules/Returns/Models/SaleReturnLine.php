<?php

namespace App\Modules\Returns\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['retour_id', 'ligne_vente_id', 'produit_id', 'nom_produit', 'quantite', 'montant', 'appareil_ids'])]
#[Table('lignes_retour')]
class SaleReturnLine extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3', 'montant' => 'decimal:2', 'appareil_ids' => 'array'];
    }
}
