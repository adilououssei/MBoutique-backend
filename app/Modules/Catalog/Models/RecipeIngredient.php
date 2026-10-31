<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recette_id', 'produit_id', 'quantite'])]
#[Table('ingredients_recette')]
class RecipeIngredient extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }
}
