<?php

namespace App\Modules\Equipment\Models;

use App\Modules\Equipment\Enums\EquipmentStatus;
use App\Modules\Equipment\Enums\EquipmentType;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nom', 'type', 'marque', 'numero_serie', 'date_acquisition', 'cout_acquisition', 'statut', 'frequence_entretien_jours', 'dernier_entretien_le', 'prochain_entretien_le', 'note', 'actif'])]
#[Table('equipements')]
class Equipment extends Model
{
    use BelongsToStore, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => EquipmentType::class,
            'statut' => EquipmentStatus::class,
            'date_acquisition' => 'date',
            'cout_acquisition' => 'decimal:2',
            'frequence_entretien_jours' => 'integer',
            'dernier_entretien_le' => 'date',
            'prochain_entretien_le' => 'date',
            'actif' => 'boolean',
        ];
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(EquipmentIntervention::class, 'equipement_id');
    }

    /** Entretien dû (échéance atteinte ou dépassée). */
    public function maintenanceDue(): bool
    {
        return $this->prochain_entretien_le !== null && $this->prochain_entretien_le->lte(now()->startOfDay());
    }
}
