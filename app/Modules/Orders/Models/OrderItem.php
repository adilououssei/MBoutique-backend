<?php

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Enums\KitchenStatus;
use App\Modules\Sales\Support\Money;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['commande_id', 'ligne_parent_id', 'vente_id', 'vente_id', 'produit_id', 'service_id', 'mode_prix', 'nom', 'quantite', 'prix_unitaire', 'note', 'statut_cuisine', 'preparation_commencee_le', 'prete_le', 'servie_le'])]
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
            'statut_cuisine' => KitchenStatus::class,
            'preparation_commencee_le' => 'datetime',
            'prete_le' => 'datetime',
            'servie_le' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'commande_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    /** Signalé indisponible par la cuisine : ni facturé, ni encaissé. */
    public function isUnavailable(): bool
    {
        return $this->statut_cuisine === KitchenStatus::Unavailable;
    }

    public function lineTotal(): string
    {
        return $this->isUnavailable() ? '0.00' : Money::round(bcmul((string) $this->prix_unitaire, (string) $this->quantite, 6));
    }
}
