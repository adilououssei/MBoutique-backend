<?php

namespace App\Modules\Repairs\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['devis_id', 'type', 'service_id', 'produit_id', 'libelle', 'quantite', 'prix_unitaire', 'montant'])]
#[Table('lignes_devis')]
class QuoteLine extends Model
{
    use BelongsToStore;

    public const TYPES = ['prestation', 'materiau', 'main_oeuvre', 'frais'];

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3', 'prix_unitaire' => 'decimal:2', 'montant' => 'decimal:2'];
    }
}
