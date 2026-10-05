<?php

namespace App\Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categorie' => $this->data['categorie'] ?? null,
            'titre' => $this->data['titre'] ?? '',
            'message' => $this->data['message'] ?? '',
            // Écran de l'application à ouvrir : { ecran: stock|commande|rendez_vous, id }.
            'lien' => $this->data['lien'] ?? null,
            'lue' => $this->read_at !== null,
            'cree_le' => $this->created_at,
        ];
    }
}
