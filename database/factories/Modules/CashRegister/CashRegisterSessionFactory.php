<?php

namespace Database\Factories\Modules\CashRegister;

use App\Modules\CashRegister\Enums\CashRegisterSessionStatus;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bypasses CashRegisterService on purpose (no Opening CashMovement is
 * created) — this factory is for tests that only need a session row to
 * exist (e.g. isolation/permission tests), not the full ledger. Tests
 * that need a real, consistent balance go through the real HTTP flow
 * instead.
 *
 * @extends Factory<CashRegisterSession>
 */
class CashRegisterSessionFactory extends Factory
{
    protected $model = CashRegisterSession::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'cash_register_id' => CashRegister::factory(),
            'opened_by_user_id' => null,
            'opened_at' => now(),
            'opening_amount' => 50000,
            'status' => CashRegisterSessionStatus::Open,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => CashRegisterSessionStatus::Closed,
            'closed_at' => now(),
            'expected_closing_amount' => 50000,
            'actual_closing_amount' => 50000,
            'difference' => 0,
        ]);
    }
}
