<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiningTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->relationLoaded('openOrder') ? $this->openOrder : null;

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'capacite' => $this->capacite,
            'actif' => $this->actif,
            // Dérivé de la commande ouverte : jamais une donnée saisie à la main.
            'occupee' => $order !== null,
            'commande' => $order ? [
                'id' => $order->id,
                'reference' => $order->reference,
                'statut' => $order->statut->value,
                'total' => $order->relationLoaded('items') ? $order->total() : null,
                'ouverte_le' => $order->created_at,
            ] : null,
        ];
    }
}
