<?php

namespace App\Modules\Repairs\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reparation_id', 'montant', 'mode', 'session_caisse_id', 'cree_par_id'])]
#[Table('paiements_reparation')]
class RepairPayment extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['montant' => 'decimal:2'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
