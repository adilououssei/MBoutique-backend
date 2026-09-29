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
            'statut' => $this->statut->value,
            'utilisateur' => new UserResource($this->whenLoaded('user')),
            'roles' => $this->whenLoaded('user', fn () => $this->user->getRoleNames()),
            'invite_le' => $this->invite_le,
            'rejoint_le' => $this->rejoint_le,
        ];
    }
}
