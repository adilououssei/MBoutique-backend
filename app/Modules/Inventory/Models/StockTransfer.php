<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marchandise envoyée d'une boutique à une autre de la même entreprise.
 * Créé uniquement par StockTransferService. Pas de BelongsToStore : le
 * transfert concerne deux boutiques — toujours le lire via involving().
 */
#[Fillable(['entreprise_id', 'boutique_source_id', 'boutique_destination_id', 'reference', 'note', 'cree_par_id'])]
#[Table('transferts_stock')]
class StockTransfer extends Model
{
    /** Transferts envoyés ou reçus par $store. */
    public function scopeInvolving(Builder $query, Store $store): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('boutique_source_id', $store->id)->orWhere('boutique_destination_id', $store->id));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_source_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_destination_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfert_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
