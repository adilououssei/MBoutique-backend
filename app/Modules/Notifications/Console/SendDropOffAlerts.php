<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\LocalTime;
use App\Modules\Notifications\Support\StoreRecipients;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Pressing : alerte interne (centre de notifications, aucun SMS) pour les
 * dépôts dus dans les prochaines heures et ceux en retard. Une alerte de
 * chaque sorte par commande.
 */
class SendDropOffAlerts extends Command
{
    protected $signature = 'notifications:depots {--heures=24 : Fenêtre « bientôt dû »}';

    protected $description = 'Signale les dépôts de pressing bientôt dus ou en retard';

    public function handle(StoreRecipients $recipients): int
    {
        $sent = 0;
        $open = [OrderStatus::Waiting->value, OrderStatus::Preparing->value, OrderStatus::Control->value, OrderStatus::Ready->value];
        // Hors requête HTTP : aucune boutique active, la portée « boutique » ne filtre pas.
        $orders = Order::query()->whereIn('statut', $open)->whereNotNull('date_promise')
            ->where('date_promise', '<', now()->addHours((int) $this->option('heures')))
            ->whereHas('articles')
            ->get();

        foreach ($orders as $order) {
            $late = $order->date_promise->isPast();
            $category = $late ? StoreAlert::DROP_OFF_LATE : StoreAlert::DROP_OFF_DUE;
            $already = DatabaseNotification::query()->where('data->categorie', $category)->where('data->commande_id', $order->id)->exists();
            if ($already) {
                continue;
            }
            $when = LocalTime::format($order->date_promise, $order->boutique_id);
            Notification::send($recipients->withPermission($order->boutique_id, 'commandes.voir'), new StoreAlert(
                $order->boutique_id,
                $category,
                $late ? 'Dépôt en retard' : 'Dépôt bientôt dû',
                "{$order->reference} · ".($order->nom_client ?: 'client').($late ? " · prévu {$when}" : " · pour {$when}"),
                ['ecran' => 'commande', 'id' => $order->id],
                ['commande_id' => $order->id],
            ));
            $sent++;
        }

        $this->info("{$sent} alerte(s) envoyée(s).");

        return self::SUCCESS;
    }
}
