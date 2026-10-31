<?php

namespace App\Modules\Transformations\Services;

use App\Modules\Catalog\Enums\ProductUnit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Support\Money;
use App\Modules\Transformations\Enums\CostAllocation;
use App\Modules\Transformations\Enums\TransformationStatus;
use App\Modules\Transformations\Enums\TransformationType;
use App\Modules\Transformations\Exceptions\InvalidTransformationException;
use App\Modules\Transformations\Exceptions\TransformationAlreadyCancelledException;
use App\Modules\Transformations\Exceptions\UnknownCostException;
use App\Modules\Transformations\Models\PreparationSheet;
use App\Modules\Transformations\Models\Transformation;
use App\Modules\Transformations\Models\TransformationItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des transformations — docs/modules.md §Transformations.
 * Découpe et préparation partagent le même moteur : des matières sortent du
 * stock (TransformationOut), des produits y entrent (TransformationIn), et le
 * coût des matières (+ frais annexes) est réparti sur les produits obtenus.
 * Ce n'est jamais un achat : aucune dette fournisseur, aucune caisse.
 */
class TransformationService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array{
     *     entrees: array<int, array{produit_id: int, quantite: string|float|int, cout_unitaire?: string|float|int|null, cout_total?: string|float|int|null}>,
     *     sorties: array<int, array{produit_id: int, quantite: string|float|int}>,
     *     methode_repartition?: string|null,
     *     frais_annexes?: string|float|int|null,
     *     fiche_preparation_id?: int|null,
     *     consommer_avant?: string|null,
     *     note?: string|null,
     *     effectuee_le?: string|null,
     *     mettre_a_jour_prix_achat?: bool,
     *     cle_idempotence?: string|null,
     * }  $data
     */
    public function record(TransformationType $type, array $data, ?int $userId): Transformation
    {
        $idempotencyKey = $data['cle_idempotence'] ?? null;
        if ($idempotencyKey !== null && ($existing = Transformation::query()->where('cle_idempotence', $idempotencyKey)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($type, $data, $userId, $idempotencyKey) {
            $productIds = collect([...$data['entrees'], ...$data['sorties']])->pluck('produit_id')->map(fn ($id) => (int) $id);
            if ($productIds->duplicates()->isNotEmpty()) {
                throw new InvalidTransformationException('Un même produit ne peut apparaître qu’une fois : il est soit consommé, soit obtenu.');
            }
            $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

            $consumed = $this->costConsumed($data['entrees'], $products);
            $materialsCost = $consumed->reduce(fn (string $carry, array $line) => bcadd($carry, $line['cout_total'], 2), '0.00');
            $extraCosts = Money::round((string) ($data['frais_annexes'] ?? '0'));
            $totalCost = bcadd($materialsCost, $extraCosts, 2);

            [$produced, $method] = $this->allocate($data['sorties'], $products, $totalCost, CostAllocation::tryFrom($data['methode_repartition'] ?? '') ?? CostAllocation::Value);

            $weightIn = $this->totalWeight($consumed);
            $weightOut = $this->totalWeight($produced);
            $loss = bccomp($weightIn, $weightOut, 3) > 0 ? bcsub($weightIn, $weightOut, 3) : '0.000';

            $performedAt = isset($data['effectuee_le']) ? Carbon::parse($data['effectuee_le']) : now();
            $transformation = $this->create([
                'type' => $type,
                'statut' => TransformationStatus::Completed,
                'fiche_preparation_id' => $data['fiche_preparation_id'] ?? null,
                'cout_matieres' => $materialsCost,
                'frais_annexes' => $extraCosts,
                'cout_total' => $totalCost,
                'poids_entree' => $weightIn,
                'poids_sortie' => $weightOut,
                'perte' => $loss,
                'methode_repartition' => $method,
                'consommer_avant' => $data['consommer_avant'] ?? $this->defaultBestBefore($data['fiche_preparation_id'] ?? null, $performedAt),
                'note' => $data['note'] ?? null,
                'effectuee_le' => $performedAt,
                'cree_par_id' => $userId,
                'cle_idempotence' => $idempotencyKey,
            ], $type);
            if (! $transformation->wasRecentlyCreated) {
                return $transformation; // perdu une course sur la clé d'idempotence
            }

            foreach ([...$consumed, ...$produced] as $line) {
                TransformationItem::create([...$line['item'], 'transformation_id' => $transformation->id]);
            }

            // Même ordre de verrous que Sales/Purchases : lignes de stock par produit_id croissant.
            $reason = ($type === TransformationType::Cutting ? 'Découpe ' : 'Préparation ').$transformation->reference;
            $moves = collect([...$consumed, ...$produced])->sortBy(fn (array $line) => $line['product']->id);
            foreach ($moves as $line) {
                $line['item']['sens'] === TransformationItem::CONSUMED
                    ? $this->inventory->removeStock($line['product'], StockMovementType::TransformationOut, $line['quantite'], $userId, $reason, $transformation)
                    : $this->inventory->receiveFromTransformation($line['product'], $line['quantite'], $userId, $reason, $transformation);
            }

            // Le coût de revient devient le prix d'achat des produits obtenus :
            // marges et bénéfice estimé des rapports s'appuient dessus.
            if ($data['mettre_a_jour_prix_achat'] ?? true) {
                foreach ($produced as $line) {
                    $line['product']->update(['prix_achat' => $line['item']['cout_unitaire']]);
                }
            }

            return $transformation->refresh();
        });
    }

    /**
     * Annule une opération : les produits obtenus ressortent du stock, les
     * matières y reviennent. Refusé si des morceaux ont déjà été vendus
     * (stock insuffisant). La ligne est conservée, statut `annulee`.
     */
    public function cancel(Transformation $transformation, ?string $reason, ?int $userId): Transformation
    {
        return DB::transaction(function () use ($transformation, $reason, $userId) {
            $transformation = Transformation::query()->whereKey($transformation->id)->lockForUpdate()->firstOrFail();
            if ($transformation->isCancelled()) {
                throw new TransformationAlreadyCancelledException("L’opération {$transformation->reference} est déjà annulée.");
            }

            $label = "Annulation {$transformation->reference}".($reason ? " : {$reason}" : '');
            $items = $transformation->items()->orderBy('produit_id')->get();
            $products = Product::withTrashed()->whereIn('id', $items->pluck('produit_id'))->get()->keyBy('id');
            foreach ($items as $item) {
                $product = $products[$item->produit_id];
                $item->isProduced()
                    ? $this->inventory->removeStock($product, StockMovementType::TransformationOut, (string) $item->quantite, $userId, $label, $transformation)
                    : $this->inventory->addStock($product, StockMovementType::TransformationIn, (string) $item->quantite, $userId, $label, $transformation);
            }

            $transformation->update([
                'statut' => TransformationStatus::Cancelled,
                'annulee_le' => now(),
                'annulee_par_id' => $userId,
                'motif_annulation' => $reason,
            ]);

            return $transformation;
        });
    }

    /**
     * Coût de chaque matière consommée : `cout_total` saisi (une carcasse
     * payée d'un bloc), sinon `cout_unitaire` saisi, sinon le prix d'achat
     * connu du produit. Sans aucun des trois, le coût de revient serait faux.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array{product: Product, quantite: string, cout_total: string, item: array<string, mixed>}>
     */
    private function costConsumed(array $lines, Collection $products): Collection
    {
        return collect($lines)->map(function (array $line) use ($products) {
            /** @var Product $product */
            $product = $products[(int) $line['produit_id']];
            $quantity = $this->quantity($line['quantite']);

            if (filled($line['cout_total'] ?? null)) {
                $total = Money::round((string) $line['cout_total']);
                $unit = Money::round(bcdiv($total, $quantity, 6));
            } else {
                $unitCost = $line['cout_unitaire'] ?? $product->prix_achat;
                if ($unitCost === null || $unitCost === '') {
                    throw new UnknownCostException("Le coût de « {$product->nom} » est inconnu : saisissez son coût ou renseignez son prix d’achat.");
                }
                $unit = Money::round((string) $unitCost);
                $total = Money::round(bcmul($unit, $quantity, 6));
            }

            return [
                'product' => $product,
                'quantite' => $quantity,
                'cout_total' => $total,
                'item' => $this->item($product, TransformationItem::CONSUMED, $quantity, $unit, $total, null),
            ];
        });
    }

    /**
     * Répartit le coût total sur les produits obtenus. Au prorata de la
     * valeur de vente par défaut ; si un produit n'a pas de prix de vente, on
     * se rabat sur le poids. Le dernier produit prend l'arrondi : la somme
     * des coûts répartis est toujours exactement le coût total.
     *
     * @param  Collection<int, Product>  $products
     * @return array{0: Collection<int, array{product: Product, quantite: string, cout_total: string, item: array<string, mixed>}>, 1: CostAllocation}
     */
    private function allocate(array $lines, Collection $products, string $totalCost, CostAllocation $requested): array
    {
        $lines = collect($lines)->map(fn (array $line) => [
            'product' => $products[(int) $line['produit_id']],
            'quantite' => $this->quantity($line['quantite']),
        ]);

        $method = $requested;
        $bases = $this->allocationBases($lines, $method);
        if ($bases === null && $method === CostAllocation::Value) {
            $method = CostAllocation::Weight;
            $bases = $this->allocationBases($lines, $method);
        }
        if ($bases === null) {
            throw new InvalidTransformationException($requested === CostAllocation::Weight
                ? 'Répartition au poids impossible : un produit obtenu n’est pas vendu au kg ou au g. Répartissez au prorata de la valeur.'
                : 'Impossible de répartir le coût : renseignez le prix de vente des produits obtenus, ou vendez-les au poids.');
        }

        $sum = array_reduce($bases, fn (string $carry, string $basis) => bcadd($carry, $basis, 6), '0');
        $allocated = '0.00';
        $last = $lines->count() - 1;

        $produced = $lines->values()->map(function (array $line, int $i) use ($bases, $sum, $totalCost, &$allocated, $last) {
            $cost = $i === $last
                ? bcsub($totalCost, $allocated, 2)
                : Money::round(bcdiv(bcmul($totalCost, $bases[$i], 6), $sum, 6));
            $allocated = bcadd($allocated, $cost, 2);
            $unit = Money::round(bcdiv($cost, $line['quantite'], 6));

            return [
                ...$line,
                'cout_total' => $cost,
                'item' => $this->item($line['product'], TransformationItem::PRODUCED, $line['quantite'], $unit, $cost, $this->salePrice($line['product'])),
            ];
        });

        return [$produced, $method];
    }

    /** @return array<int, string>|null null si la méthode n'est pas applicable à toutes les lignes */
    private function allocationBases(Collection $lines, CostAllocation $method): ?array
    {
        $bases = [];
        foreach ($lines->values() as $i => $line) {
            $basis = $method === CostAllocation::Value
                ? (($price = $this->salePrice($line['product'])) !== null ? bcmul($price, $line['quantite'], 6) : null)
                : $this->weightInKg($line['product'], $line['quantite']);
            if ($basis === null || bccomp($basis, '0', 6) <= 0) {
                return null;
            }
            $bases[$i] = $basis;
        }

        return $bases;
    }

    /** Prix de vente de référence : le détail, sinon le gros. */
    private function salePrice(Product $product): ?string
    {
        $price = $product->vente_detail_active ? $product->prix_detail : ($product->vente_gros_active ? $product->prix_gros : null);

        return $price !== null && bccomp((string) $price, '0', 2) > 0 ? (string) $price : null;
    }

    /** Poids en kg d'une quantité, si le produit se vend au poids. */
    private function weightInKg(Product $product, string $quantity): ?string
    {
        return match ($product->unite) {
            ProductUnit::Kg => $quantity,
            ProductUnit::G => bcdiv($quantity, '1000', 6),
            default => null,
        };
    }

    /** Somme des poids (kg) des lignes vendues au poids ; les pièces n'y comptent pas. */
    private function totalWeight(Collection $lines): string
    {
        $total = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $this->weightInKg($line['product'], $line['quantite']) ?? '0', 6), '0');

        return bcadd($total, '0', 3);
    }

    private function defaultBestBefore(?int $sheetId, Carbon $performedAt): ?string
    {
        $days = $sheetId !== null ? PreparationSheet::query()->whereKey($sheetId)->value('duree_conservation_jours') : null;

        return $days !== null ? $performedAt->copy()->addDays((int) $days)->toDateString() : null;
    }

    private function quantity(string|float|int $value): string
    {
        return bcadd((string) $value, '0', 3);
    }

    /** @return array<string, mixed> */
    private function item(Product $product, string $side, string $quantity, string $unitCost, string $totalCost, ?string $salePrice): array
    {
        return [
            'produit_id' => $product->id,
            'nom_produit' => $product->nom,
            'unite' => $product->unite->value,
            'sens' => $side,
            'quantite' => $quantity,
            'cout_unitaire' => $unitCost,
            'cout_total' => $totalCost,
            'prix_vente_unitaire' => $salePrice,
        ];
    }

    private function create(array $attributes, TransformationType $type): Transformation
    {
        try {
            $transformation = Transformation::create($attributes);
        } catch (QueryException $e) {
            $key = $attributes['cle_idempotence'] ?? null;
            if ($key !== null && ($e->errorInfo[0] ?? null) === '23000') {
                return Transformation::query()->where('cle_idempotence', $key)->firstOrFail();
            }
            throw $e;
        }

        $transformation->update(['reference' => sprintf('%s-%s-%06d', $type->referencePrefix(), now()->format('Ymd'), $transformation->id)]);

        return $transformation;
    }
}
