<?php

namespace App\Modules\Customers\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ordonnance_id', 'produit_id', 'libelle', 'posologie', 'quantite_prescrite', 'quantite_delivree'])]
#[Table('lignes_ordonnance')]
class PrescriptionLine extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite_prescrite' => 'decimal:3', 'quantite_delivree' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    public function remaining(): string
    {
        $left = bcsub((string) $this->quantite_prescrite, (string) $this->quantite_delivree, 3);

        return bccomp($left, '0', 3) > 0 ? $left : '0.000';
    }
}
