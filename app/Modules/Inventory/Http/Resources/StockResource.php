<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Inventory\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Always expects `product` to already be loaded by the caller — unlike
 * Category on ProductResource, the product isn't optional metadata here,
 * it's the whole point of a Stock row.
 *
 * @mixin Stock
 */
class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product' => new ProductResource($this->product),
            'quantity' => $this->quantity,
            'minimum_quantity' => $this->minimum_quantity,
            'is_low_stock' => $this->isLowStock(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
