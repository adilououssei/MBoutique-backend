<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Store
 */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'business_domain' => $this->whenLoaded('businessDomain', fn () => [
                'slug' => $this->businessDomain->slug,
                'name' => $this->businessDomain->name,
            ]),
            'name' => $this->name,
            'slug' => $this->slug,
            'address' => $this->address,
            'phone' => $this->phone,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'settings' => $this->settings,
            // Populated only when this resource wraps a StoreUser-joined
            // row (see StoreController::mine) — the caller's own role/
            // membership status on this specific store.
            'my_role' => $this->when(isset($this->my_role), fn () => $this->my_role),
            'created_at' => $this->created_at,
        ];
    }
}
