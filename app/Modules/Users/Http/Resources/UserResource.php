<?php

namespace App\Modules\Users\Http\Resources;

use App\Models\User;
use App\Modules\Auth\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'pays' => $this->pays,
            'nom_pays' => Countries::name($this->pays),
            'statut' => $this->statut->value,
            'cree_le' => $this->created_at,
        ];
    }
}
