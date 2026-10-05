<?php

namespace App\Modules\Appointments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service->id,
                'nom' => $this->service->nom,
                'prix' => $this->service->prix,
                'duree_minutes' => $this->service->duree_minutes,
            ]),
            'employe' => $this->whenLoaded('employee', fn () => $this->employee ? ['id' => $this->employee->id, 'nom' => $this->employee->nom] : null),
            'client' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'nom' => $this->customer->nom, 'telephone' => $this->customer->telephone] : null),
            // Nom et téléphone à afficher, que le client soit enregistré ou non.
            'nom_client' => $this->customer?->nom ?? $this->nom_client,
            'telephone_client' => $this->customer?->telephone ?? $this->telephone_client,
            'debut_le' => $this->debut_le,
            'fin_le' => $this->fin_le,
            'statut' => $this->statut->value,
            'notes' => $this->notes,
            'motif_annulation' => $this->motif_annulation,
            'vente_id' => $this->vente_id,
            'cree_le' => $this->created_at,
        ];
    }
}
