<?php

namespace App\Modules\Repairs\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reparation_id', 'statut', 'note', 'cree_par_id'])]
#[Table('suivis_reparation')]
class RepairFollowUp extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
