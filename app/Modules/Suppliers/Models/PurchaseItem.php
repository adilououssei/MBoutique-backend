<?php

namespace App\Modules\Suppliers\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['achat_id', 'produit_id', 'nom_produit', 'quantite', 'cout_unitaire', 'montant_total'])]
#[Table('lignes_achat')]
class PurchaseItem extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'cout_unitaire' => 'decimal:2',
            'montant_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'achat_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }
}
