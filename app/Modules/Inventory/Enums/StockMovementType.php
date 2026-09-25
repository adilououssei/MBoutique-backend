<?php

namespace App\Modules\Inventory\Enums;

/**
 * The minimal set the Phase 4.1 brief asks for — no `transfer_in`/
 * `transfer_out` yet (deferred, see docs/inventory.md §"Reporté") and no
 * arbitrary extra types. `Sale` exists in the enum because the ledger
 * must already know how to represent it, but the manual movement
 * endpoint (CreateStockMovementRequest) refuses it — only a future
 * Sales module, calling InventoryService directly, may use it.
 */
enum StockMovementType: string
{
    case Initial = 'initial';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case ReturnIn = 'return_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Stocktake = 'stocktake';
    case Loss = 'loss';

    /**
     * Fixed-direction types only — Stocktake isn't one of these, its
     * sign depends on the counted quantity vs. the previous one and is
     * computed by InventoryService::stocktake(), not here.
     */
    public function isEntry(): bool
    {
        return match ($this) {
            self::Initial, self::Purchase, self::ReturnIn, self::AdjustmentIn => true,
            self::Sale, self::AdjustmentOut, self::Loss => false,
            self::Stocktake => throw new \LogicException('Stocktake has no fixed direction; see InventoryService::stocktake().'),
        };
    }

    /** Types a merchant may record by hand through the API — Sale is reserved for the future Sales module. */
    public static function manuallyRecordable(): array
    {
        return [self::Initial, self::Purchase, self::ReturnIn, self::AdjustmentIn, self::AdjustmentOut, self::Loss, self::Stocktake];
    }
}
