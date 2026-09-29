<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Enums\ProductUnit;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Contracts\Sellable;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * A physical good. See docs/catalog.md for why this stays a plain model
 * (no inventory/stock fields here — that's Inventory, a later phase) and
 * for the Sellable contract this implements.
 */
#[Fillable(['categorie_id', 'nom', 'slug', 'description', 'sku', 'code_barres', 'unite', 'prix_achat', 'vente_detail_active', 'prix_detail', 'vente_gros_active', 'prix_gros', 'actif'])]
#[Table('produits')]
class Product extends Model implements Sellable
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'actif' => true,
        'unite' => 'piece',
    ];

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected static function booted(): void
    {
        // Belt-and-braces for the invariant FormRequest validation
        // already enforces on the payload (docs/catalog.md §4): a
        // partial update can flip *_enabled to false without touching
        // *_price at all, which validation alone wouldn't catch — this
        // guarantees the STORED row never disagrees, regardless of
        // entry point (manual, import, voice, or a raw Eloquent call).
        static::saving(function (Product $product) {
            if (! $product->vente_detail_active) {
                $product->prix_detail = null;
            }
            if (! $product->vente_gros_active) {
                $product->prix_gros = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'unite' => ProductUnit::class,
            'prix_achat' => 'decimal:2',
            'vente_detail_active' => 'boolean',
            'prix_detail' => 'decimal:2',
            'vente_gros_active' => 'boolean',
            'prix_gros' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categorie_id');
    }

    public function getSellableLabel(): string
    {
        return $this->nom;
    }

    /**
     * Generic display price — retail takes priority since it's the
     * common case. NOT what a future Sales module should use to compute
     * an actual sale amount: that must resolve the mode explicitly via
     * priceFor(), per the contract documented in docs/catalog.md §14.
     */
    public function getSellablePrice(): string
    {
        return $this->prix_detail ?? $this->prix_gros;
    }

    /**
     * The future Sales contract (docs/catalog.md §14): given an explicit
     * pricing_mode, return the price the backend — never the client —
     * is the source of truth for. Throws if that mode isn't enabled on
     * this product, so Sales can never silently sell at a disabled price.
     */
    public function priceFor(PricingMode $mode): string
    {
        return match ($mode) {
            PricingMode::Retail => $this->vente_detail_active
                ? $this->prix_detail
                : throw new InvalidArgumentException("La vente au détail n'est pas activée pour le produit #{$this->id}."),
            PricingMode::Wholesale => $this->vente_gros_active
                ? $this->prix_gros
                : throw new InvalidArgumentException("La vente en gros n'est pas activée pour le produit #{$this->id}."),
        };
    }

    public function tracksStock(): bool
    {
        // Every Product is expected to track stock once Inventory exists;
        // kept as a method (not a hardcoded true) so Sales/Inventory never
        // need an `instanceof Product` check — see docs/database.md §5.
        return true;
    }
}
