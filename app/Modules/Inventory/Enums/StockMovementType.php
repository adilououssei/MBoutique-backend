<?php

namespace App\Modules\Inventory\Enums;

/**
 * The Phase 4.1 set plus the two transfer types (docs/inventory.md
 * §"Transferts"), written only by StockTransferService. `Sale` exists in the enum because the ledger
 * must already know how to represent it, but the manual movement
 * endpoint (CreateStockMovementRequest) refuses it — only a future
 * Sales module, calling InventoryService directly, may use it.
 */
enum StockMovementType: string
{
    case Initial = 'initial';
    case Purchase = 'achat';
    case Sale = 'vente';
    case ReturnIn = 'retour';
    case AdjustmentIn = 'ajustement_entree';
    case AdjustmentOut = 'ajustement_sortie';
    case Stocktake = 'inventaire';
    case Loss = 'perte';
    case TransferOut = 'transfert_sortie';
    case TransferIn = 'transfert_entree';

    /**
     * Fixed-direction types only — Stocktake isn't one of these, its
     * sign depends on the counted quantity vs. the previous one and is
     * computed by InventoryService::stocktake(), not here.
     */
    public function isEntry(): bool
    {
        return match ($this) {
            self::Initial, self::Purchase, self::ReturnIn, self::AdjustmentIn, self::TransferIn => true,
            self::Sale, self::AdjustmentOut, self::Loss, self::TransferOut => false,
            self::Stocktake => throw new \LogicException("L'inventaire n'a pas de sens fixe ; voir InventoryService::stocktake()."),
        };
    }

    /** Types a merchant may record by hand through the API — Sale is reserved for the future Sales module. */
    public static function manuallyRecordable(): array
    {
        return [self::Initial, self::Purchase, self::ReturnIn, self::AdjustmentIn, self::AdjustmentOut, self::Loss, self::Stocktake];
    }
}
