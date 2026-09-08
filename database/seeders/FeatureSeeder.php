<?php

namespace Database\Seeders;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\FeatureDependency;
use Illuminate\Database\Seeder;

/**
 * Seeds the starting catalogue of BusinessDomain/Feature/DomainFeature
 * described in docs/features.md. All of this is plain data — adding a
 * new business type or reshaping a domain's defaults later is a matter
 * of editing rows (through a future admin API, Phase 6), never a code
 * change. See docs/feature-gate.md for the full mapping and rationale.
 */
class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = collect([
            'products' => 'Produits',
            'categories' => 'Catégories',
            'services' => 'Services',
            'inventory' => 'Stock',
            'sales' => 'Ventes',
            'cash_register' => 'Caisse',
            'customers' => 'Clients',
            'suppliers' => 'Fournisseurs',
            'employees' => 'Employés',
            'appointments' => 'Rendez-vous',
            'orders' => 'Commandes',
            'tables' => 'Tables',
            'reports' => 'Rapports',
        ])->map(fn (string $name, string $slug) => Feature::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name],
        ));

        FeatureDependency::firstOrCreate([
            'feature_id' => $features['appointments']->id,
            'depends_on_feature_id' => $features['services']->id,
        ]);
        FeatureDependency::firstOrCreate([
            'feature_id' => $features['appointments']->id,
            'depends_on_feature_id' => $features['employees']->id,
        ]);
        FeatureDependency::firstOrCreate([
            'feature_id' => $features['tables']->id,
            'depends_on_feature_id' => $features['orders']->id,
        ]);

        $domains = [
            'general_store' => ['Alimentation générale', ['products', 'categories', 'inventory', 'sales', 'cash_register', 'customers', 'suppliers', 'employees', 'reports']],
            'butchery' => ['Boucherie', ['products', 'categories', 'inventory', 'sales', 'cash_register', 'customers', 'suppliers', 'employees', 'reports']],
            'restaurant' => ['Restaurant', ['products', 'inventory', 'tables', 'orders', 'sales', 'cash_register', 'customers', 'employees', 'reports']],
            'hair_salon' => ['Coiffure', ['customers', 'services', 'appointments', 'employees', 'sales', 'cash_register', 'reports']],
            'beauty_salon' => ['Salon de beauté', ['customers', 'services', 'appointments', 'employees', 'sales', 'cash_register', 'reports']],
            'pharmacy' => ['Pharmacie', ['products', 'categories', 'inventory', 'sales', 'cash_register', 'customers', 'suppliers', 'employees', 'reports']],
            'clothing' => ['Boutique de vêtements', ['products', 'categories', 'inventory', 'sales', 'cash_register', 'customers', 'employees', 'reports']],
            'electronics' => ['Électronique', ['products', 'categories', 'inventory', 'sales', 'cash_register', 'customers', 'suppliers', 'employees', 'reports']],
            'workshop' => ['Atelier', ['services', 'orders', 'customers', 'employees', 'sales', 'cash_register', 'reports']],
            'laundry' => ['Pressing', ['services', 'orders', 'customers', 'employees', 'sales', 'cash_register', 'reports']],
            'other' => ['Autre', ['products', 'services', 'sales', 'cash_register', 'customers', 'reports']],
        ];

        foreach ($domains as $slug => [$name, $featureSlugs]) {
            $domain = BusinessDomain::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);

            foreach ($featureSlugs as $featureSlug) {
                $domain->domainFeatures()->firstOrCreate(
                    ['feature_id' => $features[$featureSlug]->id],
                    ['is_default_enabled' => true],
                );
            }
        }
    }
}
