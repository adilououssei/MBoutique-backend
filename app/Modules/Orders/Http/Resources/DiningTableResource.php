<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiningTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->relationLoaded('openOrder') ? $this->openOrder : null;
        $reservation = $this->getAttribute('reservation_proche');

        // Dérivé, jamais saisi : commande ouverte (addition demandée ou non),
        // réservation proche, nettoyage à faire, sinon disponible.
        $status = match (true) {
            $order !== null && $order->addition_demandee_le !== null => 'attente_paiement',
            $order !== null => 'occupee',
            $reservation !== null => 'reservee',
            (bool) $this->a_nettoyer => 'a_nettoyer',
            default => 'disponible',
        };

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'espace' => $this->espace,
            'capacite' => $this->capacite,
            'actif' => $this->actif,
            'a_nettoyer' => (bool) $this->a_nettoyer,
            'statut' => $status,
            'occupee' => $order !== null,
            'commande' => $order ? [
                'id' => $order->id,
                'reference' => $order->reference,
                'statut' => $order->statut->value,
                'total' => $order->relationLoaded('items') ? $order->total() : null,
                'nombre_couverts' => $order->nombre_couverts,
                'addition_demandee_le' => $order->addition_demandee_le,
                'ouverte_le' => $order->created_at,
            ] : null,
            'reservation' => $reservation,
        ];
    }
}
