<?php

namespace App\Modules\Transformations\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fiche_preparation_id', 'produit_id', 'quantite'])]
#[Table('ingredients_fiche')]
class PreparationSheetIngredient extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3'];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(PreparationSheet::class, 'fiche_preparation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }
}
