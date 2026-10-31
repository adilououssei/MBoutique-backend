<?php

namespace App\Modules\Employees\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Horaires d'un employé pour un jour de la semaine (1 = lundi … 7 = dimanche), pause facultative. */
#[Fillable(['employe_id', 'jour', 'debut', 'fin', 'pause_debut', 'pause_fin'])]
#[Table('horaires_employes')]
class EmployeeSchedule extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['jour' => 'integer'];
    }
}
