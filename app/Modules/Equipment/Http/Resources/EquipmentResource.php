<?php

namespace App\Modules\Equipment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'type' => $this->type->value,
            'libelle_type' => $this->type->label(),
            'stockage' => $this->type->isStorage(),
            'marque' => $this->marque,
            'numero_serie' => $this->numero_serie,
            'date_acquisition' => $this->date_acquisition?->toDateString(),
            'cout_acquisition' => $this->cout_acquisition,
            'statut' => $this->statut->value,
            'frequence_entretien_jours' => $this->frequence_entretien_jours,
            'dernier_entretien_le' => $this->dernier_entretien_le?->toDateString(),
            'prochain_entretien_le' => $this->prochain_entretien_le?->toDateString(),
            'entretien_du' => $this->maintenanceDue(),
            'note' => $this->note,
            'actif' => $this->actif,
            'interventions' => $this->whenLoaded('interventions', fn () => $this->interventions->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->type->value,
                'survenue_le' => $i->survenue_le,
                'description' => $i->description,
                'cout' => $i->cout,
                'prestataire' => $i->prestataire,
                'depense_id' => $i->depense_id,
                'cree_par' => $i->createdBy ? ['id' => $i->createdBy->id, 'nom' => $i->createdBy->nom] : null,
            ])),
        ];
    }
}
