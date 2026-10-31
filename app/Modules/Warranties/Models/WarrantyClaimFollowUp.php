<?php

namespace App\Modules\Warranties\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reclamation_id', 'statut', 'note', 'cree_par_id'])]
#[Table('suivis_reclamation')]
class WarrantyClaimFollowUp extends Model
{
    use BelongsToStore;

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
