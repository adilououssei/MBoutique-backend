<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockAlreadyInitializedException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place that ever writes to `stocks.quantity` or creates a
 * StockMovement — see docs/inventory.md §"Service central". A future
 * Sales module decrementing stock on checkout must call removeStock()
 * with StockMovementType::Sale, never touch Stock directly (docs/inventory.md §14).
 */
class InventoryService
{
    public function initializeStock(
        Product $product,
        string $quantity,
        ?string $minimumQuantity,
        ?int $createdByUserId,
        ?string $reason = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $quantity, $minimumQuantity, $createdByUserId, $reason) {
            if (Stock::query()->where('product_id', $product->id)->exists()) {
                throw new StockAlreadyInitializedException("Le stock de \"{$product->name}\" est déjà initialisé.");
            }

            try {
                $stock = Stock::create([
                    'product_id' => $product->id,
                    'quantity' => 0,
                    'minimum_quantity' => $minimumQuantity,
                ]);
            } catch (QueryException $e) {
                // Closes the tiny window between the exists() check above
                // and this insert: the (store_id, product_id) unique
                // constraint is the real guarantee, this just turns a
                // concurrent double-initialize into a clean 422 instead
                // of a raw 500 from an unhandled QueryException.
                throw $this->isUniqueConstraintViolation($e)
                    ? new StockAlreadyInitializedException("Le stock de \"{$product->name}\" est déjà initialisé.")
                    : $e;
            }

            return $this->applyDelta($stock, $product, StockMovementType::Initial, $quantity, $reason, $createdByUserId);
        });
    }

    /** @param  StockMovementType  $type  one of Purchase, ReturnIn, AdjustmentIn */
    public function addStock(
        Product $product,
        StockMovementType $type,
        string $quantity,
        ?int $createdByUserId,
        ?string $reason = null,
    ): StockMovement {
        if (! $type->isEntry()) {
            throw new InvalidArgumentException("{$type->value} is not an entry movement type.");
        }

        return DB::transaction(function () use ($product, $type, $quantity, $createdByUserId, $reason) {
            $stock = $this->lockExistingStock($product);

            return $this->applyDelta($stock, $product, $type, $quantity, $reason, $createdByUserId);
        });
    }

    /**
     * @param  StockMovementType  $type  Loss, AdjustmentOut, or Sale (Sales calling
     *                                   this directly with a Sale as $reference — see docs/inventory.md §14)
     */
    public function removeStock(
        Product $product,
        StockMovementType $type,
        string $quantity,
        ?int $createdByUserId,
        ?string $reason = null,
        ?Model $reference = null,
    ): StockMovement {
        if ($type->isEntry()) {
            throw new InvalidArgumentException("{$type->value} is not an exit movement type.");
        }

        return DB::transaction(function () use ($product, $type, $quantity, $createdByUserId, $reason, $reference) {
            $stock = $this->lockExistingStock($product);

            if (bccomp((string) $stock->quantity, $quantity, 3) < 0) {
                throw InsufficientStockException::forProduct($product, (string) $stock->quantity, $quantity);
            }

            return $this->applyDelta($stock, $product, $type, bcmul($quantity, '-1', 3), $reason, $createdByUserId, $reference);
        });
    }

    /** $countedQuantity is the absolute physical count, not a delta — the delta is computed here. */
    public function stocktake(
        Product $product,
        string $countedQuantity,
        ?int $createdByUserId,
        ?string $reason = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $countedQuantity, $createdByUserId, $reason) {
            $stock = $this->lockExistingStock($product);

            $delta = bcsub($countedQuantity, (string) $stock->quantity, 3);

            return $this->applyDelta($stock, $product, StockMovementType::Stocktake, $delta, $reason, $createdByUserId);
        });
    }

    /** A threshold, not a ledger fact — updated directly, no StockMovement produced. */
    public function updateMinimumQuantity(Product $product, ?string $minimumQuantity): Stock
    {
        return DB::transaction(function () use ($product, $minimumQuantity) {
            $stock = $this->lockExistingStock($product);
            $stock->update(['minimum_quantity' => $minimumQuantity]);

            return $stock;
        });
    }

    private function lockExistingStock(Product $product): Stock
    {
        $stock = Stock::query()->where('product_id', $product->id)->lockForUpdate()->first();

        if ($stock === null) {
            throw new StockNotInitializedException("Le stock de \"{$product->name}\" n'a pas encore été initialisé.");
        }

        return $stock;
    }

    private function applyDelta(
        Stock $stock,
        Product $product,
        StockMovementType $type,
        string $delta,
        ?string $reason,
        ?int $createdByUserId,
        ?Model $reference = null,
    ): StockMovement {
        $before = (string) $stock->quantity;
        $after = bcadd($before, $delta, 3);

        $movement = StockMovement::create([
            'stock_id' => $stock->id,
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $delta,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'created_by_user_id' => $createdByUserId,
        ]);

        $stock->update(['quantity' => $after]);

        return $movement->setRelation('stock', $stock)->setRelation('product', $product);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
