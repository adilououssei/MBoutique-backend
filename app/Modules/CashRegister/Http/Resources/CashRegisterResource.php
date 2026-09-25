<?php

namespace App\Modules\CashRegister\Http\Resources;

use App\Modules\CashRegister\Models\CashRegister;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegister
 */
class CashRegisterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'is_open' => $this->isOpen(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
