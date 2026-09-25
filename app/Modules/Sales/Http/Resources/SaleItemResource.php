<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Sales\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'pricing_mode' => $this->pricing_mode->value,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total' => $this->total_amount,
        ];
    }
}
