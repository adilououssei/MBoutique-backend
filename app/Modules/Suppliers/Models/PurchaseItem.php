<?php

namespace App\Modules\Suppliers\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['achat_id', 'produit_id', 'nom_produit', 'quantite', 'quantite_commandee', 'cout_unitaire', 'cout_revient_unitaire', 'montant_total', 'numero_lot', 'date_peremption', 'fabrique_le'])]
#[Table('lignes_achat')]
class PurchaseItem extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'date_peremption' => 'date',
            'fabrique_le' => 'date',
            'quantite' => 'decimal:3',
            'cout_unitaire' => 'decimal:2',
            'cout_revient_unitaire' => 'decimal:2',
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
