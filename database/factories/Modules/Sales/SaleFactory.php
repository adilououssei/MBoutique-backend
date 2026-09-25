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
            'store_id' => Store::factory(),
            'cash_register_id' => CashRegister::factory(),
            'cash_register_session_id' => CashRegisterSession::factory(),
            'customer_id' => null,
            'sold_by_user_id' => null,
            'reference' => 'VTE-'.now()->format('Ymd').'-'.fake()->unique()->numberBetween(1, 999999),
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'total_amount' => $subtotal,
            'status' => SaleStatus::Completed,
            'payment_method' => PaymentMethod::Cash,
            'idempotency_key' => null,
            'sold_at' => now(),
        ];
    }
}
