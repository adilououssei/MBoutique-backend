<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Models\LoyaltyMovement;
use App\Modules\Appointments\Support\SalonSettings;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;

/**
 * Fidélité : 1 point par tranche de `montant_par_point` dépensée, 1 point =
 * `valeur_point` de remise. Points gagnés et utilisés à l'encaissement,
 * repris si la vente est annulée ; historique dans mouvements_fidelite.
 */
class LoyaltyService
{
    /** Valeur de remise de `points` points pour ce client (contrôle du solde). */
    public function discountFor(Store $store, Customer $customer, int $points): string
    {
        $settings = SalonSettings::loyalty($store) ?? throw new SalonException('La fidélité n’est pas paramétrée : fixez le barème dans les réglages du salon.', 'FIDELITE_NON_PARAMETREE');
        if ($points > $customer->points_fidelite) {
            throw new SalonException("{$customer->nom} n’a que {$customer->points_fidelite} point(s).", 'POINTS_INSUFFISANTS');
        }

        return Money::round(bcmul((string) $points, $settings['valeur_point'], 4));
    }

    public function pointsEarned(Store $store, string $amount): int
    {
        $settings = SalonSettings::loyalty($store);
        if ($settings === null || bccomp($amount, '0', 2) <= 0) {
            return 0;
        }

        return (int) bcdiv($amount, $settings['montant_par_point'], 0);
    }

    /** Encaissement : points utilisés puis points gagnés sur le montant payé. */
    public function recordForSale(Store $store, Sale $sale, Customer $customer, int $usedPoints, string $usedValue, ?int $userId): void
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
        if ($usedPoints > 0) {
            $this->move($customer, -$usedPoints, 'utilisation', $sale->id, $usedValue, "Remise sur la vente {$sale->reference}", $userId);
        }
        $earned = $this->pointsEarned($store, (string) $sale->montant_total);
        if ($earned > 0) {
            $this->move($customer, $earned, 'gain', $sale->id, null, "Vente {$sale->reference}", $userId);
        }
    }

    /** Vente annulée : chaque mouvement de la vente est contre-passé (le solde peut devenir négatif si les points ont été dépensés). */
    public function reverseForSale(Sale $sale, ?int $userId): void
    {
        $movements = LoyaltyMovement::query()->where('vente_id', $sale->id)->whereIn('type', ['gain', 'utilisation'])->get();
        foreach ($movements as $movement) {
            $customer = Customer::withTrashed()->lockForUpdate()->find($movement->client_id);
            if ($customer !== null) {
                $this->move($customer, -$movement->points, 'annulation', $sale->id, null, "Annulation de la vente {$sale->reference}", $userId);
            }
        }
    }

    public function adjust(Customer $customer, int $points, ?string $note, ?int $userId): LoyaltyMovement
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

        return $this->move($customer, $points, 'ajustement', null, null, $note, $userId);
    }

    private function move(Customer $customer, int $points, string $type, ?int $saleId, ?string $value, ?string $note, ?int $userId): LoyaltyMovement
    {
        $balance = $customer->points_fidelite + $points;
        $customer->forceFill(['points_fidelite' => $balance])->save();

        return LoyaltyMovement::create([
            'client_id' => $customer->id,
            'vente_id' => $saleId,
            'type' => $type,
            'points' => $points,
            'solde_apres' => $balance,
            'valeur' => $value,
            'note' => $note,
            'cree_par_id' => $userId,
        ]);
    }
}
