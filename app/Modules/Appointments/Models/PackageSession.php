<?php

namespace App\Modules\Appointments\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Séance de forfait consommée : décomptée quand le rendez-vous est terminé, jamais avant. */
#[Fillable(['forfait_client_id', 'rendez_vous_id', 'utilisee_le', 'cree_par_id'])]
#[Table('seances_forfait')]
class PackageSession extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['utilisee_le' => 'datetime'];
    }
}
