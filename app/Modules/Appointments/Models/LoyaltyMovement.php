<?php

namespace App\Modules\Appointments\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Historique des points de fidélité d'un client : gain, utilisation, annulation, ajustement. */
#[Fillable(['client_id', 'vente_id', 'type', 'points', 'solde_apres', 'valeur', 'note', 'cree_par_id'])]
#[Table('mouvements_fidelite')]
class LoyaltyMovement extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['points' => 'integer', 'solde_apres' => 'integer', 'valeur' => 'decimal:2'];
    }
}
