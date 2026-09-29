<?php

namespace Database\Factories\Modules\Subscriptions;

use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'entreprise_id' => Business::factory(),
            'forfait_id' => Plan::factory(),
            'statut' => 'actif',
            'debut_periode_le' => now(),
            'fin_periode_le' => now()->addMonth(),
        ];
    }
}
