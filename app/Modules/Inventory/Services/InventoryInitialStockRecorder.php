<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Contracts\InitialStockRecorder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;

/** Implémentation Inventory du contrat de Catalog (stock initial à l'import). */
class InventoryInitialStockRecorder implements InitialStockRecorder
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly FeatureGate $features,
    ) {}

    public function tracksStock(Store $store): bool
    {
        return $this->features->allows($store, 'stock');
    }

    public function record(Product $product, string $quantity, ?string $minimumQuantity, ?int $userId, string $reason): void
    {
        $this->inventory->initializeStock($product, $quantity, $minimumQuantity, $userId, $reason);
    }
}
