<?php

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Sales\Support\Money;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commande_id', 'produit_id', 'service_id', 'mode_prix', 'nom', 'quantite', 'prix_unitaire', 'note'])]
#[Table('lignes_commande')]
class OrderItem extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return [
            'mode_prix' => PricingMode::class,
            'quantite' => 'decimal:3',
            'prix_unitaire' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'commande_id');
    }

    public function lineTotal(): string
    {
        return Money::round(bcmul((string) $this->prix_unitaire, (string) $this->quantite, 6));
    }
}
