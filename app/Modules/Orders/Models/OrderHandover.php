<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Remise (totale ou partielle) d'articles déposés : qui, quand, combien de pièces, somme reçue. */
#[Fillable(['commande_id', 'mode', 'nombre_articles', 'montant_paye', 'note', 'remis_par_id', 'remis_le'])]
#[Table('remises_depot')]
class OrderHandover extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['montant_paye' => 'decimal:2', 'remis_le' => 'datetime', 'nombre_articles' => 'integer'];
    }

    public function handedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remis_par_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DropOffItem::class, 'remise_id');
    }
}
