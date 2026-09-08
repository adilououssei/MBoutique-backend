<?php

namespace Database\Factories\Modules\Tenancy;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'business_id' => Business::factory(),
            'business_domain_id' => BusinessDomain::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'currency' => 'XOF',
            'timezone' => 'Africa/Abidjan',
            'status' => 'active',
            'settings' => [],
        ];
    }
}
