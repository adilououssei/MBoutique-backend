<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Produit consommé par les prestations d'une vente : prévu et réellement sorti du stock. */
#[Fillable(['vente_id', 'commande_id', 'produit_id', 'quantite_prevue', 'quantite', 'cout_unitaire', 'ajustee'])]
#[Table('consommations_prestations')]
class ServiceConsumption extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite_prevue' => 'decimal:3', 'quantite' => 'decimal:3', 'cout_unitaire' => 'decimal:2', 'ajustee' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }
}
