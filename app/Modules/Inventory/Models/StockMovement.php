<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Inventory\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only ledger entry. Never updated or deleted by any endpoint in
 * this module — a mistake is corrected with a new movement, not an edit
 * of this one. See docs/inventory.md §"Immutabilité". Only
 * InventoryService creates these; no controller calls
 * StockMovement::create() directly.
 */
#[Fillable(['stock_id', 'produit_id', 'type', 'quantite', 'quantite_avant', 'quantite_apres', 'reference_type', 'reference_id', 'motif', 'metadonnees', 'cree_par_id'])]
#[Table('mouvements_stock')]
class StockMovement extends Model
{
    use BelongsToStore, HasFactory;

    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantite' => 'decimal:3',
            'quantite_avant' => 'decimal:3',
            'quantite_apres' => 'decimal:3',
            'metadonnees' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class, 'stock_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
