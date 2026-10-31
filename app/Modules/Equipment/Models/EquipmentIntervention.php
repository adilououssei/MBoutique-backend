<?php

namespace App\Modules\Equipment\Models;

use App\Models\User;
use App\Modules\Equipment\Enums\InterventionType;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['equipement_id', 'type', 'survenue_le', 'description', 'cout', 'prestataire', 'depense_id', 'cree_par_id'])]
#[Table('interventions_equipement')]
class EquipmentIntervention extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'type' => InterventionType::class,
            'survenue_le' => 'datetime',
            'cout' => 'decimal:2',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipement_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
