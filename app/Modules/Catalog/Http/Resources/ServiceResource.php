<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie' => new CategoryResource($this->whenLoaded('category')),
            'nom' => $this->nom,
            'slug' => $this->slug,
            'description' => $this->description,
            'prix' => $this->prix,
            'duree_minutes' => $this->duree_minutes,
            'actif' => $this->actif,
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
