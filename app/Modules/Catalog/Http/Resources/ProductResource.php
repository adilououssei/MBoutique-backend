<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'unit' => $this->unit->value,
            'purchase_price' => $this->purchase_price,
            'retail_enabled' => $this->retail_enabled,
            'retail_price' => $this->retail_price,
            'wholesale_enabled' => $this->wholesale_enabled,
            'wholesale_price' => $this->wholesale_price,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
