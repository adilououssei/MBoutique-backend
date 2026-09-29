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
            'produit' => new ProductResource($this->product),
            'quantite' => $this->quantite,
            'quantite_minimum' => $this->quantite_minimum,
            'stock_faible' => $this->isLowStock(),
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
