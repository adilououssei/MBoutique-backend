<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Enums\ProductCondition;
use App\Modules\Catalog\Enums\ProductConservation;
use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Enums\ProductUnit;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Contracts\Sellable;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * A physical good. See docs/catalog.md for why this stays a plain model
 * (no inventory/stock fields here — that's Inventory, a later phase) and
 * for the Sellable contract this implements.
 */
#[Fillable(['categorie_id', 'fournisseur_id', 'nom', 'marque', 'modele', 'etat', 'caracteristiques', 'suivi_unitaire', 'garantie_mois', 'conditions_garantie', 'slug', 'description', 'sku', 'code_barres', 'unite', 'quantites_vente', 'type_article', 'temps_preparation_minutes', 'disponible', 'dci', 'forme', 'dosage', 'presentation', 'laboratoire', 'sur_ordonnance', 'unites_par_boite', 'produit_parent_id', 'taille', 'couleur', 'public_cible', 'collection_id', 'conservation', 'duree_conservation_jours', 'prix_achat', 'vente_detail_active', 'prix_detail', 'vente_gros_active', 'prix_gros', 'actif'])]
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
            // Quantités proposées à la pesée : triées, au gramme près ; liste vide = aucune.
            if ($product->isDirty('quantites_vente') && $product->quantites_vente !== null) {
                $quantities = array_values(array_unique(array_map(fn ($q) => round((float) $q, 3), $product->quantites_vente)));
                sort($quantities);
                $product->quantites_vente = $quantities === [] ? null : $quantities;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'unite' => ProductUnit::class,
            'conservation' => ProductConservation::class,
            'etat' => ProductCondition::class,
            'suivi_unitaire' => 'boolean',
            'type_article' => ProductKind::class,
            'temps_preparation_minutes' => 'integer',
            'disponible' => 'boolean',
            'sur_ordonnance' => 'boolean',
            'unites_par_boite' => 'integer',
            'quantites_vente' => 'array',
            'garantie_mois' => 'integer',
            'duree_conservation_jours' => 'integer',
            'prix_achat' => 'decimal:2',
            'vente_detail_active' => 'boolean',
            'prix_detail' => 'decimal:2',
            'vente_gros_active' => 'boolean',
            'prix_gros' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    /**
     * Quantité de stock pour une quantité vendue : une vente « gros » d'un
     * produit conditionné (unités par boîte) sort N unités par boîte.
     */
    public function stockQuantityFor(?PricingMode $mode, string $quantity): string
    {
        if ($mode === PricingMode::Wholesale && ($this->unites_par_boite ?? 0) > 1) {
            return bcmul($quantity, (string) $this->unites_par_boite, 3);
        }

        return $quantity;
    }

    /** Article parent d'une variante (taille / couleur). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'produit_parent_id')->withTrashed();
    }

    /** Variantes d'un article : chacune a son stock, son prix, son code-barres. */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'produit_parent_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    /** Suppléments payants proposés avec cet article (eux-mêmes des produits). */
    public function supplements(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'supplements_produit', 'produit_id', 'supplement_id')->withTimestamps();
    }

    /** @param  array<int, int>  $supplementIds */
    public function syncSupplements(array $supplementIds): void
    {
        $this->supplements()->sync(collect($supplementIds)->reject(fn (int $id) => $id === $this->id)
            ->mapWithKeys(fn (int $id) => [$id => ['boutique_id' => $this->boutique_id]])->all());
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    /** Fournisseur habituel (fiche technique), facultatif. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'fournisseur_id')->withTrashed();
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
