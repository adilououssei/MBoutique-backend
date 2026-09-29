<?php

namespace Database\Factories\Modules\Catalog;

use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'boutique_id' => Store::factory(),
            'categorie_id' => null,
            'nom' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->optional()->sentence(),
            'prix' => fake()->randomFloat(2, 500, 10000),
            'duree_minutes' => fake()->randomElement([null, 15, 30, 45, 60]),
            'actif' => true,
        ];
    }
}
