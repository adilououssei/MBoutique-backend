<?php

namespace App\Modules\Conservation\Models;

use App\Modules\Catalog\Enums\ProductConservation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Suppliers\Models\Supplier;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['produit_id', 'fournisseur_id', 'achat_id', 'mouvement_entree_id', 'numero', 'numero_lot', 'quantite_initiale', 'quantite_restante', 'prix_achat', 'recu_le', 'fabrique_le', 'date_limite', 'statut', 'motif_blocage', 'conservation', 'equipement_id', 'note'])]
#[Table('lots_stock')]
class StockLot extends Model
{
    use BelongsToStore;

    protected $attributes = [
        'statut' => 'actif',
    ];

    /** Lot « bientôt périmé » : date limite dans ce nombre de jours ou moins. */
    public const SOON_DAYS = 2;

    protected function casts(): array
    {
        return [
            'quantite_initiale' => 'decimal:3',
            'quantite_restante' => 'decimal:3',
            'recu_le' => 'datetime',
            'date_limite' => 'date',
            'fabrique_le' => 'date',
            'prix_achat' => 'decimal:2',
            'conservation' => ProductConservation::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produit_id')->withTrashed();
    }

    /** Espace de conservation (module Equipment), facultatif. */
    public function storage(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipement_id')->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'fournisseur_id')->withTrashed();
    }

    public function journal(): HasMany
    {
        return $this->hasMany(LotJournalEntry::class, 'lot_id')->latest('id');
    }

    /** Vendable : actif et pas périmé (un lot sans date reste vendable). */
    public function isSellable(): bool
    {
        return ($this->statut ?? 'actif') === 'actif' && ($this->daysLeft() === null || $this->daysLeft() >= 0);
    }

    public function entryMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'mouvement_entree_id');
    }

    /** Jours avant la date limite (négatif si dépassée), null sans date. */
    public function daysLeft(): ?int
    {
        return $this->date_limite === null ? null : (int) now()->startOfDay()->diffInDays($this->date_limite->copy()->startOfDay(), false);
    }

    /** `perime`, `bientot`, `ok` ou `sans_date`. */
    public function state(): string
    {
        $days = $this->daysLeft();

        return match (true) {
            $days === null => 'sans_date',
            $days < 0 => 'perime',
            $days <= self::SOON_DAYS => 'bientot',
            default => 'ok',
        };
    }
}
