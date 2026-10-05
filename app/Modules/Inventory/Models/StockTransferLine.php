<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['transfert_id', 'produit_source_id', 'produit_destination_id', 'nom_produit', 'quantite', 'produit_cree'])]
#[Table('lignes_transfert_stock')]
class StockTransferLine extends Model
{
    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'produit_cree' => 'boolean',
        ];
    }
}
