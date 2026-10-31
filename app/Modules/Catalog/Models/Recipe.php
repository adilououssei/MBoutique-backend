<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Recette d'un plat : ingrédients pour `portions` portions (RecipeService). */
#[Fillable(['produit_id', 'portions', 'note'])]
#[Table('recettes')]
class Recipe extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['portions' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'recette_id');
    }
}
