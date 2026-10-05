<?php

namespace App\Modules\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Alerte affichée dans le centre de notifications de l'application.
 *
 * Canal `database` uniquement pour l'instant. Les notifications push
 * (application fermée) s'ajouteront dans via() quand l'application aura un
 * build de développement (expo-notifications n'est plus disponible dans Expo Go).
 */
class StoreAlert extends Notification
{
    use Queueable;

    public const STOCK = 'stock';

    public const ORDER_READY = 'commande_prete';

    public const APPOINTMENT_BOOKED = 'rendez_vous';

    public const APPOINTMENT_REMINDER = 'rappel_rendez_vous';

    /**
     * @param  array{ecran: string, id: int|string}|null  $link  écran de l'application à ouvrir
     * @param  array<string, mixed>  $extra  données de déduplication (ex. rendez_vous_id)
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?array $link = null,
        public readonly array $extra = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'boutique_id' => $this->storeId,
            'categorie' => $this->category,
            'titre' => $this->title,
            'message' => $this->message,
            'lien' => $this->link,
            ...$this->extra,
        ];
    }
}
