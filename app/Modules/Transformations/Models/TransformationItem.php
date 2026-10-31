<?php

namespace App\Modules\Transformations\Models;

use App\Modules\Catalog\Models\Product;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transformation_id', 'produit_id', 'nom_produit', 'unite', 'sens', 'quantite', 'cout_unitaire', 'cout_total', 'prix_vente_unitaire'])]
#[Table('lignes_transformation')]
class TransformationItem extends Model
{
    use BelongsToStore;

    public const CONSUMED = 'consommee';

    public const PRODUCED = 'obtenue';

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'cout_unitaire' => 'decimal:2',
            'cout_total' => 'decimal:2',
            'prix_vente_unitaire' => 'decimal:2',
        ];
    }

    public function transformation(): BelongsTo
    {
        return $this->belongsTo(Transformation::class, 'transformation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    public function isProduced(): bool
    {
        return $this->sens === self::PRODUCED;
    }

    /** Marge potentielle d'une ligne obtenue : valeur de vente − coût de revient (null sans prix de vente). */
    public function potentialMargin(): ?string
    {
        if (! $this->isProduced() || $this->prix_vente_unitaire === null) {
            return null;
        }

        $value = bcmul((string) $this->prix_vente_unitaire, (string) $this->quantite, 6);

        return number_format((float) bcsub($value, (string) $this->cout_total, 6), 2, '.', '');
    }
}
