<?php

namespace App\Modules\Conservation\Console;

use App\Modules\Conservation\Models\StockLot;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\StoreRecipients;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Chaque matin : lots encore en stock bientôt périmés ou périmés → ceux qui
 * gèrent le stock (`stock.ajuster`). Idempotente : une alerte par lot et par
 * état (« bientôt », puis « périmé »), jamais répétée.
 */
class SendExpiryAlerts extends Command
{
    protected $signature = 'conservation:alertes-peremption';

    protected $description = 'Alerte sur les lots proches de leur date limite ou périmés';

    public function handle(StoreRecipients $recipients, FeatureGate $features): int
    {
        $sent = 0;
        // Hors requête HTTP : aucune boutique active, la portée « boutique » ne filtre pas.
        $lots = StockLot::query()
            ->with('product')
            ->where('quantite_restante', '>', 0)
            ->whereNotNull('date_limite')
            ->whereDate('date_limite', '<=', now()->addDays(StockLot::SOON_DAYS)->toDateString())
            ->get()
            ->groupBy('boutique_id');

        foreach ($lots as $storeId => $storeLots) {
            $store = Store::query()->find($storeId);
            if ($store === null || ! $features->allows($store, 'conservation')) {
                continue;
            }

            foreach ($storeLots as $lot) {
                $state = $lot->state();
                $already = DatabaseNotification::query()
                    ->where('data->categorie', StoreAlert::EXPIRY)
                    ->where('data->lot_id', $lot->id)
                    ->where('data->etat', $state)
                    ->exists();
                if ($already) {
                    continue;
                }

                $quantity = (rtrim(rtrim((string) $lot->quantite_restante, '0'), '.') ?: '0').' '.$lot->product?->unite->value;
                $date = $lot->date_limite->format('d/m/Y');
                $alert = $state === 'perime'
                    ? new StoreAlert($storeId, StoreAlert::EXPIRY, 'Produit périmé', "« {$lot->product?->nom} » : {$quantity} du lot {$lot->numero} ont dépassé leur date limite ({$date}).", ['ecran' => 'conservation', 'id' => $lot->id], ['lot_id' => $lot->id, 'etat' => $state])
                    : new StoreAlert($storeId, StoreAlert::EXPIRY, 'Date limite proche', "« {$lot->product?->nom} » : {$quantity} du lot {$lot->numero} à écouler avant le {$date}.", ['ecran' => 'conservation', 'id' => $lot->id], ['lot_id' => $lot->id, 'etat' => $state]);

                Notification::send($recipients->withPermission($storeId, 'stock.ajuster'), $alert);
                $sent++;
            }
        }

        $this->info("{$sent} alerte(s) envoyée(s).");

        return self::SUCCESS;
    }
}
