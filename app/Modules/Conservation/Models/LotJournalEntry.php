<?php

namespace App\Modules\Conservation\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal append-only d'un lot : création, modification, blocage, rappel, perte. */
#[Fillable(['lot_id', 'action', 'details', 'cree_par_id'])]
#[Table('journal_lots')]
class LotJournalEntry extends Model
{
    use BelongsToStore;

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
