<?php

namespace Database\Factories\Modules\Employees;

use App\Modules\Employees\Enums\SalaryPeriod;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'boutique_id' => Store::factory(),
            'utilisateur_id' => null,
            'nom' => fake()->name(),
            'poste' => fake()->randomElement(['Vendeur', 'Magasinier', 'Coiffeuse', 'Livreur']),
            'telephone' => fake()->phoneNumber(),
            'adresse' => null,
            'date_embauche' => fake()->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'salaire' => 60000,
            'periodicite_salaire' => SalaryPeriod::Monthly,
            'notes' => null,
            'actif' => true,
        ];
    }
}
