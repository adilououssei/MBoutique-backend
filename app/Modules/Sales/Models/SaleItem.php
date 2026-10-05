<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Sales\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot line, not a live reference — `product_name`/`unit_price`
 * are frozen at the time of sale so a later Product rename or price
 * change never alters a past receipt. See docs/sales.md §"Snapshot".
 * Product-only in this phase: Service isn't sellable through Sales yet
 * (pricing_mode/détail-gros are Product-specific concepts, see
 * docs/sales.md §"Écart d'architecture").
 */
#[Fillable(['vente_id', 'produit_id', 'service_id', 'nom_produit', 'mode_prix', 'prix_unitaire', 'quantite', 'montant_remise', 'montant_total'])]
#[Table('lignes_vente')]
class SaleItem extends Model
{
    use BelongsToStore, HasFactory;

    protected function casts(): array
    {
        return [
            'mode_prix' => PricingMode::class,
            'prix_unitaire' => 'decimal:2',
            'quantite' => 'decimal:3',
            'montant_remise' => 'decimal:2',
            'montant_total' => 'decimal:2',
        ];
    }

    protected static function newFactory(): SaleItemFactory
    {
        return SaleItemFactory::new();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'vente_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /** Une ligne porte soit un produit (stock suivi), soit un service. */
    public function isService(): bool
    {
        return $this->service_id !== null;
    }
}
