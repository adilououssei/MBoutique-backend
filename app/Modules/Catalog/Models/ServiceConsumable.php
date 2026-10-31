<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Produit consommé par une prestation (salon) : quantité par prestation réalisée. */
#[Fillable(['service_id', 'produit_id', 'quantite'])]
#[Table('consommables_services')]
class ServiceConsumable extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['quantite' => 'decimal:3'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }
}
