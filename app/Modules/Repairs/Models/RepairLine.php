<?php

namespace App\Modules\Repairs\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Ligne facturée d'un dossier : prestation, matériau forfaitaire, main-d'œuvre, frais ou remise (négative). */
#[Fillable(['reparation_id', 'type', 'service_id', 'libelle', 'quantite', 'prix_unitaire', 'montant'])]
#[Table('prestations_reparation')]
class RepairLine extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3', 'prix_unitaire' => 'decimal:2', 'montant' => 'decimal:2'];
    }
}
