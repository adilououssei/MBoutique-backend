<?php

namespace Database\Factories\Modules\CashRegister;

use App\Modules\CashRegister\Enums\CashMovementType;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 1000, 20000);

        return [
            'store_id' => Store::factory(),
            'cash_register_session_id' => CashRegisterSession::factory(),
            'type' => CashMovementType::CashIn,
            'amount' => $amount,
            'balance_before' => 0,
            'balance_after' => $amount,
            'reason' => null,
            'created_by_user_id' => null,
        ];
    }
}
