<?php

namespace Database\Factories\Modules\Sales;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bypasses SaleService on purpose (no SaleItem, no stock/cash
 * side-effects) — for tests that only need a Sale row to exist
 * (isolation/permission tests). Tests exercising the real checkout flow
 * go through the HTTP endpoint instead — same pattern as
 * CashRegisterSessionFactory.
 *
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 500, 50000);

        return [
            'boutique_id' => Store::factory(),
            'caisse_id' => CashRegister::factory(),
            'session_caisse_id' => CashRegisterSession::factory(),
            'client_id' => null,
            'vendeur_id' => null,
            'reference' => 'VTE-'.now()->format('Ymd').'-'.fake()->unique()->numberBetween(1, 999999),
            'sous_total' => $subtotal,
            'montant_remise' => 0,
            'montant_total' => $subtotal,
            'statut' => SaleStatus::Completed,
            'mode_paiement' => PaymentMethod::Cash,
            'cle_idempotence' => null,
            'vendue_le' => now(),
        ];
    }
}
