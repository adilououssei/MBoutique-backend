<?php

namespace App\Modules\Equipment\Console;

use App\Modules\Equipment\Models\Equipment;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Notifications\Notifications\StoreAlert;
use App\Modules\Notifications\Support\StoreRecipients;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Chaque matin : équipements actifs dont l'entretien arrive à échéance
 * (aujourd'hui ou demain) → `equipements.gerer`. Une alerte par équipement et
 * par échéance.
 */
class SendMaintenanceAlerts extends Command
{
    protected $signature = 'equipements:alertes-entretien';

    protected $description = 'Rappelle les entretiens d’équipements à faire';

    public function handle(StoreRecipients $recipients, FeatureGate $features): int
    {
        $sent = 0;
        $due = Equipment::query()
            ->where('actif', true)
            ->whereNotNull('prochain_entretien_le')
            ->whereDate('prochain_entretien_le', '<=', now()->addDay()->toDateString())
            ->get()
            ->groupBy('boutique_id');

        foreach ($due as $storeId => $items) {
            $store = Store::query()->find($storeId);
            if ($store === null || ! $features->allows($store, 'equipements')) {
                continue;
            }

            foreach ($items as $equipment) {
                $date = $equipment->prochain_entretien_le->toDateString();
                $already = DatabaseNotification::query()
                    ->where('data->categorie', StoreAlert::MAINTENANCE)
                    ->where('data->equipement_id', $equipment->id)
                    ->where('data->echeance', $date)
                    ->exists();
                if ($already) {
                    continue;
                }

                Notification::send($recipients->withPermission($storeId, 'equipements.gerer'), new StoreAlert(
                    $storeId,
                    StoreAlert::MAINTENANCE,
                    'Entretien à faire',
                    "« {$equipment->nom} » : entretien prévu le {$equipment->prochain_entretien_le->format('d/m/Y')}.",
                    ['ecran' => 'equipement', 'id' => $equipment->id],
                    ['equipement_id' => $equipment->id, 'echeance' => $date],
                ));
                $sent++;
            }
        }

        $this->info("{$sent} alerte(s) envoyée(s).");

        return self::SUCCESS;
    }
}
