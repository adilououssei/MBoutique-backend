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
            'boutique_id' => Store::factory(),
            'caisse_id' => CashRegister::factory(),
            'ouverte_par_id' => null,
            'ouverte_le' => now(),
            'montant_ouverture' => 50000,
            'statut' => CashRegisterSessionStatus::Open,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'statut' => CashRegisterSessionStatus::Closed,
            'fermee_le' => now(),
            'montant_fermeture_attendu' => 50000,
            'montant_fermeture_reel' => 50000,
            'ecart' => 0,
        ]);
    }
}
