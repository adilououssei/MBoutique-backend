<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;

/**
 * Permet à Catalog (import Excel) d'initialiser le stock d'un produit sans
 * dépendre d'Inventory — qui dépend lui-même de Catalog. Implémenté par
 * Inventory et lié dans InventoryServiceProvider (inversion de dépendance).
 */
interface InitialStockRecorder
{
    /** La boutique suit-elle les quantités (feature `stock`) ? */
    public function tracksStock(Store $store): bool;

    public function record(Product $product, string $quantity, ?string $minimumQuantity, ?int $userId, string $reason): void;
}
