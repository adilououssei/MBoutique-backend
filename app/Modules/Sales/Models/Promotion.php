<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['nom', 'type', 'valeur', 'debut_le', 'fin_le', 'portee', 'categorie_id', 'collection_id', 'actif', 'cree_par_id'])]
#[Table('promotions')]
class Promotion extends Model
{
    use BelongsToStore;

    protected $attributes = [
        'actif' => true,
    ];

    protected function casts(): array
    {
        return ['valeur' => 'decimal:2', 'debut_le' => 'datetime', 'fin_le' => 'datetime', 'actif' => 'boolean'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'produits_promotion', 'promotion_id', 'produit_id')->withTimestamps();
    }

    /** En cours maintenant : active, commencée, pas encore finie. */
    public function scopeRunning(Builder $query): void
    {
        $query->where('actif', true)->where('debut_le', '<=', now())->where(fn ($q) => $q->whereNull('fin_le')->orWhere('fin_le', '>=', now()));
    }

    public function isRunning(): bool
    {
        return $this->actif && $this->debut_le->lte(now()) && ($this->fin_le === null || $this->fin_le->gte(now()));
    }
}
