<?php

namespace App\Modules\Sales\Http\Resources;

use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Sales\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `discount`/`total` here, not `discount_amount`/`total_amount` (the
 * column names) — matches the receipt contract exactly as specified by
 * the Phase 4.3 brief §17, a deliberate API-vs-storage naming split.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'sold_by' => $this->whenLoaded('soldBy', fn () => $this->soldBy ? ['id' => $this->soldBy->id, 'name' => $this->soldBy->name] : null),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount_amount,
            'total' => $this->total_amount,
            'payment_method' => $this->payment_method->value,
            'status' => $this->status->value,
            'sold_at' => $this->sold_at,
        ];
    }
}
