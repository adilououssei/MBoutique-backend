<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\StoreUser;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreUser
 */
class StoreUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'user' => new UserResource($this->whenLoaded('user')),
            'roles' => $this->whenLoaded('user', fn () => $this->user->getRoleNames()),
            'invited_at' => $this->invited_at,
            'joined_at' => $this->joined_at,
        ];
    }
}
