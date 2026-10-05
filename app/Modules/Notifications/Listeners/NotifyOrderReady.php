<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\StoreRecipients;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Notification;

/** Commande prête → l'équipe qui sert/encaisse (`commandes.voir`), sauf celui qui l'a marquée prête. */
class NotifyOrderReady
{
    public function __construct(private readonly StoreRecipients $recipients) {}

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order->loadMissing('diningTable');
        if ($order->statut !== OrderStatus::Ready) {
            return;
        }

        $where = $order->diningTable?->nom
            ?? ($order->type === OrderType::DropOff ? 'à retirer' : ($order->nom_client ?: $order->reference));
        $title = $order->type === OrderType::DropOff ? 'Dépôt prêt' : 'Commande prête';

        Notification::send(
            $this->recipients->withPermission($order->boutique_id, 'commandes.voir', $event->userId),
            new StoreAlert($order->boutique_id, StoreAlert::ORDER_READY, $title, "{$order->reference} · {$where}", ['ecran' => 'commande', 'id' => $order->id]),
        );
    }
}
