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
            'categorie' => new CategoryResource($this->whenLoaded('category')),
            'nom' => $this->nom,
            'slug' => $this->slug,
            'description' => $this->description,
            // asset() suit l'hôte de la requête (IP du PC vue par le téléphone),
            // contrairement à Storage::url() qui figerait APP_URL (localhost).
            'image_url' => $this->image ? asset('storage/'.$this->image) : null,
            'sku' => $this->sku,
            'code_barres' => $this->code_barres,
            'unite' => $this->unite->value,
            'prix_achat' => $this->prix_achat,
            'vente_detail_active' => $this->vente_detail_active,
            'prix_detail' => $this->prix_detail,
            'vente_gros_active' => $this->vente_gros_active,
            'prix_gros' => $this->prix_gros,
            'actif' => $this->actif,
            'cree_le' => $this->created_at,
            'modifie_le' => $this->updated_at,
        ];
    }
}
