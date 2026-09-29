<?php

namespace App\Modules\CashRegister\Http\Resources;

use App\Modules\CashRegister\Models\CashRegisterSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegisterSession
 */
class CashRegisterSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'statut' => $this->statut->value,
            'montant_ouverture' => $this->montant_ouverture,
            // Only meaningful while open — once closed, actual/expected/
            // difference already fully describe the final state.
            'solde_courant' => $this->isOpen() ? $this->movements()->latest('id')->value('solde_apres') : null,
            'montant_fermeture_attendu' => $this->montant_fermeture_attendu,
            'montant_fermeture_reel' => $this->montant_fermeture_reel,
            'ecart' => $this->ecart,
            'note_fermeture' => $this->note_fermeture,
            'ouverte_par' => $this->whenLoaded('openedBy', fn () => $this->openedBy ? ['id' => $this->openedBy->id, 'nom' => $this->openedBy->nom] : null),
            'fermee_par' => $this->whenLoaded('closedBy', fn () => $this->closedBy ? ['id' => $this->closedBy->id, 'nom' => $this->closedBy->nom] : null),
            'ouverte_le' => $this->ouverte_le,
            'fermee_le' => $this->fermee_le,
        ];
    }
}
