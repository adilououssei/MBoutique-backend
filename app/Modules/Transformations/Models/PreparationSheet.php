<?php

namespace App\Modules\Transformations\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Fiche de préparation : ce qu'il faut consommer pour fabriquer une fournée d'un produit fini. */
#[Fillable(['produit_id', 'nom', 'quantite_produite', 'duree_conservation_jours', 'instructions', 'actif'])]
#[Table('fiches_preparation')]
class PreparationSheet extends Model
{
    use BelongsToStore, SoftDeletes;

    protected function casts(): array
    {
        return [
            'quantite_produite' => 'decimal:3',
            'duree_conservation_jours' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(PreparationSheetIngredient::class, 'fiche_preparation_id');
    }
}
