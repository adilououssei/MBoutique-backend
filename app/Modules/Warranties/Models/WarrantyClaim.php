<?php

namespace App\Modules\Warranties\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['garantie_id', 'numero', 'description', 'statut', 'resolution', 'cree_par_id', 'cloturee_le'])]
#[Table('reclamations_garantie')]
class WarrantyClaim extends Model
{
    use BelongsToStore;

    public const STATUSES = ['ouverte', 'en_cours', 'resolue', 'refusee'];

    public const OPEN_STATUSES = ['ouverte', 'en_cours'];

    protected function casts(): array
    {
        return ['cloturee_le' => 'datetime'];
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'garantie_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(WarrantyClaimFollowUp::class, 'reclamation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
