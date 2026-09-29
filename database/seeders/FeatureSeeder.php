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
            'produits' => 'Produits',
            'categories' => 'Catégories',
            'services' => 'Services',
            'stock' => 'Stock',
            'ventes' => 'Ventes',
            'caisse' => 'Caisse',
            'clients' => 'Clients',
            'fournisseurs' => 'Fournisseurs',
            'employes' => 'Employés',
            'rendez_vous' => 'Rendez-vous',
            'commandes' => 'Commandes',
            'tables' => 'Tables',
            'rapports' => 'Rapports',
        ])->map(fn (string $name, string $slug) => Feature::query()->firstOrCreate(
            ['slug' => $slug],
            ['nom' => $name],
        ));

        FeatureDependency::firstOrCreate([
            'fonctionnalite_id' => $features['rendez_vous']->id,
            'depend_de_fonctionnalite_id' => $features['services']->id,
        ]);
        FeatureDependency::firstOrCreate([
            'fonctionnalite_id' => $features['rendez_vous']->id,
            'depend_de_fonctionnalite_id' => $features['employes']->id,
        ]);
        FeatureDependency::firstOrCreate([
            'fonctionnalite_id' => $features['tables']->id,
            'depend_de_fonctionnalite_id' => $features['commandes']->id,
        ]);

        $domains = [
            'alimentation_generale' => ['Alimentation générale', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports']],
            'boucherie' => ['Boucherie', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports']],
            'restaurant' => ['Restaurant', ['produits', 'stock', 'tables', 'commandes', 'ventes', 'caisse', 'clients', 'employes', 'rapports']],
            'coiffure' => ['Coiffure', ['clients', 'services', 'rendez_vous', 'employes', 'ventes', 'caisse', 'rapports']],
            'salon_beaute' => ['Salon de beauté', ['clients', 'services', 'rendez_vous', 'employes', 'ventes', 'caisse', 'rapports']],
            'pharmacie' => ['Pharmacie', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports']],
            'vetements' => ['Boutique de vêtements', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'employes', 'rapports']],
            'electronique' => ['Électronique', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports']],
            'atelier' => ['Atelier', ['services', 'commandes', 'clients', 'employes', 'ventes', 'caisse', 'rapports']],
            'pressing' => ['Pressing', ['services', 'commandes', 'clients', 'employes', 'ventes', 'caisse', 'rapports']],
            'autre' => ['Autre', ['produits', 'services', 'ventes', 'caisse', 'clients', 'rapports']],
        ];

        foreach ($domains as $slug => [$name, $featureSlugs]) {
            $domain = BusinessDomain::query()->firstOrCreate(['slug' => $slug], ['nom' => $name]);

            foreach ($featureSlugs as $featureSlug) {
                $domain->domainFeatures()->firstOrCreate(
                    ['fonctionnalite_id' => $features[$featureSlug]->id],
                    ['active_par_defaut' => true],
                );
            }
        }
    }
}
