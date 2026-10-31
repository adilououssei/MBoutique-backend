<?php

namespace App\Modules\Conservation\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Conservation\Exceptions\LotNotSellableException;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Inventory\Enums\LossOrigin;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Suppliers\Models\Purchase;
use App\Modules\Suppliers\Models\PurchaseItem;
use App\Modules\Transformations\Models\Transformation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tient les lots à jour à partir des mouvements de stock (appelé dans la
 * transaction du mouvement, via StockMovementRecorded) — docs/modules.md
 * §Conservation. Une entrée crée un lot daté, une sortie consomme les lots
 * dont la date limite est la plus proche (puis les plus anciens).
 */
class LotService
{
    /** Lot choisi pour la prochaine sortie d'un produit (vente : lot désigné par le vendeur). */
    private array $preferred = [];

    /** Pharmacie : la prochaine sortie ne prend que des lots vendables (ni périmés, ni bloqués, ni rappelés). */
    private bool $strict = false;

    /** Détail de la dernière sortie : [[lot_id, quantité]…] (lots enregistrés sur la ligne de vente). */
    private array $lastConsumption = [];

    public function __construct(private readonly InventoryService $inventory) {}

    public function prefer(int $productId, ?int $lotId, bool $strict): void
    {
        $this->preferred[$productId] = $lotId;
        $this->strict = $strict;
        $this->lastConsumption = [];
    }

    /** @return array<int, array{0: int, 1: string}> */
    public function takeConsumption(): array
    {
        $taken = $this->lastConsumption;
        $this->lastConsumption = [];
        $this->preferred = [];
        $this->strict = false;

        return $taken;
    }

    /**
     * Quantité vendable d'un produit : lots vendables + stock antérieur au
     * suivi (sans lot). Refuse un lot désigné non vendable ou insuffisant.
     */
    public function assertSellable(Product $product, string $quantity, ?int $lotId, string $stockQuantity): void
    {
        $lots = StockLot::query()->where('produit_id', $product->id)->where('quantite_restante', '>', 0)->get();
        if ($lotId !== null) {
            $lot = $lots->firstWhere('id', $lotId) ?? throw new LotNotSellableException("Ce lot de « {$product->nom} » n’existe pas ou est épuisé.");
            if (! $lot->isSellable()) {
                throw new LotNotSellableException('Le lot '.($lot->numero_lot ?? $lot->numero)." de « {$product->nom} » est ".($lot->statut === 'actif' ? 'périmé' : ($lot->statut === 'rappele' ? 'rappelé' : 'bloqué')).' : vente refusée.');
            }
        }
        $sellable = $lots->filter(fn (StockLot $l) => $l->isSellable())->reduce(fn (string $c, StockLot $l) => bcadd($c, (string) $l->quantite_restante, 3), '0');
        $tracked = $lots->reduce(fn (string $c, StockLot $l) => bcadd($c, (string) $l->quantite_restante, 3), '0');
        $untracked = bccomp($stockQuantity, $tracked, 3) > 0 ? bcsub($stockQuantity, $tracked, 3) : '0';
        $available = bcadd($sellable, $untracked, 3);
        if (bccomp($quantity, $available, 3) > 0) {
            throw new LotNotSellableException("« {$product->nom} » : seulement ".rtrim(rtrim($available, '0'), '.').' unité(s) vendable(s) — les lots périmés, bloqués ou rappelés sont exclus.');
        }
    }

    /** Annulation d'une vente : les quantités reviennent dans les lots d'où elles sont sorties. */
    public function restoreFromSale(int $saleId, Product $product, string $quantity): bool
    {
        $rows = DB::table('lots_vente')->join('lignes_vente', 'lignes_vente.id', '=', 'lots_vente.ligne_vente_id')
            ->where('lignes_vente.vente_id', $saleId)->where('lignes_vente.produit_id', $product->id)
            ->get(['lots_vente.lot_id', 'lots_vente.quantite']);
        if ($rows->isEmpty()) {
            return false;
        }
        $left = $quantity;
        foreach ($rows as $row) {
            if (bccomp($left, '0', 3) <= 0) {
                break;
            }
            $back = bccomp((string) $row->quantite, $left, 3) < 0 ? (string) $row->quantite : $left;
            $lot = StockLot::query()->whereKey($row->lot_id)->lockForUpdate()->first();
            if ($lot !== null) {
                $lot->update(['quantite_restante' => bcadd((string) $lot->quantite_restante, $back, 3)]);
                $this->journal($lot, 'retour', "Annulation de vente : +{$back}", null);
            }
            $left = bcsub($left, $back, 3);
        }

        return true;
    }

    /** Action sensible inscrite au journal du lot. */
    public function journal(StockLot $lot, string $action, ?string $details, ?int $userId): void
    {
        $lot->journal()->create(['action' => $action, 'details' => $details, 'cree_par_id' => $userId]);
    }

    /** Entrée en stock : un lot, daté par la préparation, sinon par la durée de conservation du produit. */
    public function recordEntry(StockMovement $movement, Product $product): StockLot
    {
        $receivedAt = $movement->created_at ?? now();
        $lot = StockLot::create([
            'produit_id' => $product->id,
            'mouvement_entree_id' => $movement->id,
            'quantite_initiale' => (string) $movement->quantite,
            'quantite_restante' => (string) $movement->quantite,
            'recu_le' => $receivedAt,
            'date_limite' => $this->bestBefore($movement, $product, $receivedAt->copy()),
            'conservation' => $product->conservation,
        ]);
        $lot->update(['numero' => sprintf('LOT-%s-%05d', $receivedAt->format('ymd'), $lot->id)]);

        // Réception d'un achat : n° du fabricant, péremption, fabrication, prix et fournisseur saisis sur la ligne.
        if ($movement->reference_type === (new Purchase)->getMorphClass()) {
            $line = PurchaseItem::query()->where('achat_id', $movement->reference_id)->where('produit_id', $product->id)->orderBy('id')->get()
                ->first(fn (PurchaseItem $item) => ! StockLot::query()->where('achat_id', $movement->reference_id)->where('produit_id', $product->id)->whereKeyNot($lot->id)->where('numero_lot', $item->numero_lot)->exists());
            $purchase = Purchase::query()->find($movement->reference_id);
            $lot->update(array_filter([
                'numero_lot' => $line?->numero_lot,
                'date_limite' => $line?->date_peremption?->toDateString() ?? $lot->date_limite?->toDateString(),
                'fabrique_le' => $line?->fabrique_le?->toDateString(),
                'prix_achat' => $line?->cout_revient_unitaire ?? $line?->cout_unitaire,
                'fournisseur_id' => $purchase?->fournisseur_id,
                'achat_id' => $purchase?->id,
            ], fn ($v) => $v !== null));
        }
        $this->journal($lot, 'creation', "Entrée de {$movement->quantite}".($lot->numero_lot ? " · lot {$lot->numero_lot}" : ''), $movement->cree_par_id ?? null);

        return $lot;
    }

    /**
     * Sortie de stock : consomme `$quantity` sur les lots du produit, celui
     * désigné d'abord (perte d'un lot précis), puis date limite la plus
     * proche, puis le plus ancien. Le stock d'avant le suivi n'a pas de lot :
     * ce qui dépasse les lots n'est simplement pas suivi.
     */
    public function consume(Product $product, string $quantity, ?int $preferredLotId = null): void
    {
        $preferredLotId ??= $this->preferred[$product->id] ?? null;
        $strict = $this->strict;
        $lots = StockLot::query()
            ->where('produit_id', $product->id)
            ->where('quantite_restante', '>', 0)
            // Vente en pharmacie : jamais un lot bloqué, rappelé ou périmé.
            ->when($strict, fn ($q) => $q->where('statut', 'actif')->where(fn ($q) => $q->whereNull('date_limite')->orWhereDate('date_limite', '>=', now()->toDateString())))
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredLotId ?? 0])
            ->orderByRaw('CASE WHEN date_limite IS NULL THEN 1 ELSE 0 END')
            ->orderBy('date_limite')
            ->orderBy('recu_le')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $left = $quantity;
        foreach ($lots as $lot) {
            if (bccomp($left, '0', 3) <= 0) {
                break;
            }
            $taken = bccomp((string) $lot->quantite_restante, $left, 3) < 0 ? (string) $lot->quantite_restante : $left;
            $lot->update(['quantite_restante' => bcsub((string) $lot->quantite_restante, $taken, 3)]);
            $this->lastConsumption[] = [$lot->id, $taken];
            $left = bcsub($left, $taken, 3);
        }
    }

    /**
     * Déclare perdu (par défaut tout le reste d'un lot périmé) : un mouvement
     * `perte` valorisé, référencé sur le lot, qui consomme ce lot en priorité.
     */
    public function declareLoss(StockLot $lot, ?string $quantity, LossOrigin $origin, ?string $reason, ?int $userId): StockMovement
    {
        return DB::transaction(function () use ($lot, $quantity, $origin, $reason, $userId) {
            $lot = StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $quantity ??= (string) $lot->quantite_restante;
            if (bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, (string) $lot->quantite_restante, 3) > 0) {
                throw new \InvalidArgumentException("La quantité perdue doit être comprise entre 0 et le reste du lot ({$lot->quantite_restante}).");
            }

            return $this->inventory->removeStock(
                $lot->product,
                StockMovementType::Loss,
                $quantity,
                $userId,
                $reason ?? "Lot {$lot->numero} : {$origin->label()}",
                $lot,
                $origin,
            );
        });
    }

    private function bestBefore(StockMovement $movement, Product $product, CarbonInterface $receivedAt): ?string
    {
        if ($movement->reference_type === 'transformation' && class_exists(Transformation::class)) {
            $date = Transformation::query()->whereKey($movement->reference_id)->value('consommer_avant');
            if ($date !== null) {
                return (string) $date;
            }
        }

        return $product->duree_conservation_jours !== null
            ? $receivedAt->addDays($product->duree_conservation_jours)->toDateString()
            : null;
    }
}
