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
            'boutique_id' => Store::factory(),
            'session_caisse_id' => CashRegisterSession::factory(),
            'type' => CashMovementType::CashIn,
            'montant' => $amount,
            'solde_avant' => 0,
            'solde_apres' => $amount,
            'motif' => null,
            'cree_par_id' => null,
        ];
    }
}
