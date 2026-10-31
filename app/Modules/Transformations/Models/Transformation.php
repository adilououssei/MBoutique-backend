<?php

namespace App\Modules\Transformations\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Enums\CostAllocation;
use App\Modules\Transformations\Enums\TransformationStatus;
use App\Modules\Transformations\Enums\TransformationType;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'reference', 'statut', 'fiche_preparation_id', 'cout_matieres', 'frais_annexes', 'cout_total', 'poids_entree', 'poids_sortie', 'perte', 'methode_repartition', 'consommer_avant', 'note', 'effectuee_le', 'cree_par_id', 'annulee_le', 'annulee_par_id', 'motif_annulation', 'cle_idempotence'])]
#[Table('transformations')]
class Transformation extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'type' => TransformationType::class,
            'statut' => TransformationStatus::class,
            'methode_repartition' => CostAllocation::class,
            'cout_matieres' => 'decimal:2',
            'frais_annexes' => 'decimal:2',
            'cout_total' => 'decimal:2',
            'poids_entree' => 'decimal:3',
            'poids_sortie' => 'decimal:3',
            'perte' => 'decimal:3',
            'consommer_avant' => 'date',
            'effectuee_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransformationItem::class, 'transformation_id');
    }

    public function preparationSheet(): BelongsTo
    {
        return $this->belongsTo(PreparationSheet::class, 'fiche_preparation_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par_id');
    }

    public function isCancelled(): bool
    {
        return $this->statut === TransformationStatus::Cancelled;
    }

    /** Part du poids d'entrée perdue à la découpe, en % (null sans poids d'entrée). */
    public function lossRate(): ?string
    {
        if (bccomp((string) $this->poids_entree, '0', 3) <= 0) {
            return null;
        }

        return bcdiv(bcmul((string) $this->perte, '100', 6), (string) $this->poids_entree, 1);
    }
}
