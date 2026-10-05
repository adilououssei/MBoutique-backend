<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\StoreRecipients;
use Illuminate\Support\Facades\Notification;

/**
 * Stock passé sous son seuil minimum, ou en rupture → ceux qui gèrent le
 * stock (`stock.ajuster`). Une seule alerte au franchissement du seuil, pas
 * à chaque vente suivante.
 */
class NotifyLowStock
{
    public function __construct(private readonly StoreRecipients $recipients) {}

    public function handle(StockLevelChanged $event): void
    {
        if (! $event->crossedLowThreshold()) {
            return;
        }

        $product = $event->product;
        $storeId = $product->boutique_id;
        $quantity = rtrim(rtrim($event->after, '0'), '.') ?: '0';

        $alert = $event->isOutOfStock()
            ? new StoreAlert($storeId, StoreAlert::STOCK, 'Rupture de stock', "« {$product->nom} » est en rupture de stock.", ['ecran' => 'stock', 'id' => $product->id])
            : new StoreAlert($storeId, StoreAlert::STOCK, 'Stock faible', "« {$product->nom} » : plus que {$quantity} en stock (minimum {$this->trim((string) $event->stock->quantite_minimum)}).", ['ecran' => 'stock', 'id' => $product->id]);

        Notification::send($this->recipients->withPermission($storeId, 'stock.ajuster'), $alert);
    }

    private function trim(string $decimal): string
    {
        return rtrim(rtrim($decimal, '0'), '.') ?: '0';
    }
}
