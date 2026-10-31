<?php

namespace App\Modules\Employees\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Congé, absence ou arrêt maladie d'un employé, du … au … inclus (dates locales de la boutique). */
#[Fillable(['employe_id', 'type', 'du', 'au', 'motif', 'cree_par_id'])]
#[Table('absences_employes')]
class EmployeeAbsence extends Model
{
    use BelongsToStore;

    public const TYPES = ['conge' => 'Congé', 'absence' => 'Absence', 'maladie' => 'Maladie'];

    protected function casts(): array
    {
        return ['du' => 'date', 'au' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employe_id');
    }
}
