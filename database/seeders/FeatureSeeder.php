<?php

namespace Database\Seeders;

use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\FeatureDependency;
use App\Modules\Features\Support\FeatureCatalog;
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
            'decoupe' => 'Découpe',
            'preparations' => 'Préparations',
            'conservation' => 'Conservation et pertes',
            'depenses' => 'Dépenses',
            'equipements' => 'Équipements',
            'rapports_gestion' => 'Rapport de gestion',
            'creation_vocale' => 'Création vocale',
            'import_produits' => 'Import Excel',
            'marques_modeles' => 'Marques et modèles',
            'appareils' => 'Appareils (IMEI / n° de série)',
            'garanties' => 'Garanties',
            'retours' => 'Retours et échanges',
            'reparations' => 'Réparations',
            'rapports' => 'Rapports',
            'supplements' => 'Suppléments',
            'recettes' => 'Recettes',
            'cuisine' => 'Cuisine',
            'reservations' => 'Réservations',
            'devis' => 'Devis',
            'variantes' => 'Tailles et couleurs',
            'collections' => 'Collections et saisons',
            'promotions' => 'Promotions et soldes',
            'lots_stricts' => 'Lots contrôlés à la vente',
            'conditionnement' => 'Vente à la boîte ou à l’unité',
            'commandes_fournisseurs' => 'Commandes fournisseurs',
            'ordonnances' => 'Ordonnances',
            'consommables' => 'Consommables des prestations',
            'forfaits' => 'Forfaits et abonnements',
            'fidelite' => 'Fidélité',
            'commissions' => 'Commissions',
            'articles_depot' => 'Articles déposés',
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

        // Découpe et préparations transforment le stock : sans stock ni produits, rien à transformer.
        foreach (['appareils' => 'stock', 'garanties' => 'ventes', 'retours' => 'ventes'] as $tracked => $dependency) {
            FeatureDependency::firstOrCreate([
                'fonctionnalite_id' => $features[$tracked]->id,
                'depend_de_fonctionnalite_id' => $features[$dependency]->id,
            ]);
        }

        foreach (['creation_vocale', 'import_produits', 'marques_modeles', 'appareils', 'garanties'] as $productTool) {
            FeatureDependency::firstOrCreate([
                'fonctionnalite_id' => $features[$productTool]->id,
                'depend_de_fonctionnalite_id' => $features['produits']->id,
            ]);
        }

        foreach (['decoupe', 'preparations', 'conservation'] as $transformation) {
            foreach (['stock', 'produits'] as $dependency) {
                FeatureDependency::firstOrCreate([
                    'fonctionnalite_id' => $features[$transformation]->id,
                    'depend_de_fonctionnalite_id' => $features[$dependency]->id,
                ]);
            }
        }

        FeatureDependency::firstOrCreate([
            'fonctionnalite_id' => $features['devis']->id,
            'depend_de_fonctionnalite_id' => $features['clients']->id,
        ]);

        // Le stock suit des produits (2026-11-22).
        FeatureDependency::firstOrCreate(['fonctionnalite_id' => $features['stock']->id, 'depend_de_fonctionnalite_id' => $features['produits']->id]);

        // Pressing (2026-11-21).
        foreach (['commandes', 'clients'] as $dependency) {
            FeatureDependency::firstOrCreate(['fonctionnalite_id' => $features['articles_depot']->id, 'depend_de_fonctionnalite_id' => $features[$dependency]->id]);
        }

        // Salon de beauté (2026-11-19).
        foreach (['consommables' => ['services', 'stock', 'produits'], 'forfaits' => ['rendez_vous', 'clients', 'ventes'], 'fidelite' => ['clients', 'ventes'], 'commissions' => ['employes', 'ventes']] as $slug => $dependencies) {
            foreach ($dependencies as $dependency) {
                FeatureDependency::firstOrCreate(['fonctionnalite_id' => $features[$slug]->id, 'depend_de_fonctionnalite_id' => $features[$dependency]->id]);
            }
        }

        // Pharmacie (2026-11-18).
        foreach (['lots_stricts' => ['conservation', 'ventes'], 'conditionnement' => ['produits', 'stock'], 'commandes_fournisseurs' => ['fournisseurs'], 'ordonnances' => ['ventes', 'clients']] as $slug => $dependencies) {
            foreach ($dependencies as $dependency) {
                FeatureDependency::firstOrCreate(['fonctionnalite_id' => $features[$slug]->id, 'depend_de_fonctionnalite_id' => $features[$dependency]->id]);
            }
        }

        // Vêtements (2026-11-17).
        foreach (['variantes' => ['produits', 'stock'], 'collections' => ['produits'], 'promotions' => ['ventes', 'produits']] as $slug => $dependencies) {
            foreach ($dependencies as $dependency) {
                FeatureDependency::firstOrCreate(['fonctionnalite_id' => $features[$slug]->id, 'depend_de_fonctionnalite_id' => $features[$dependency]->id]);
            }
        }

        // Restaurant (2026-11-15).
        foreach (['supplements' => ['commandes', 'produits'], 'recettes' => ['stock', 'produits'], 'cuisine' => ['commandes'], 'reservations' => ['tables']] as $slug => $dependencies) {
            foreach ($dependencies as $dependency) {
                FeatureDependency::firstOrCreate([
                    'fonctionnalite_id' => $features[$slug]->id,
                    'depend_de_fonctionnalite_id' => $features[$dependency]->id,
                ]);
            }
        }

        FeatureDependency::firstOrCreate([
            'fonctionnalite_id' => $features['rapports_gestion']->id,
            'depend_de_fonctionnalite_id' => $features['rapports']->id,
        ]);

        foreach (FeatureCatalog::FEATURES as $slug => [$category, $description]) {
            $feature = $features[$slug] ?? null;
            $feature?->fill(['categorie' => $feature->categorie ?? $category, 'description' => $feature->description ?? $description])->save();
        }

        // Options par métier : désactivées par défaut, chaque boutique choisit (Paramètres du module).
        $optional = ['electronique' => ['reparations'], 'atelier' => ['preparations'], 'salon_beaute' => ['commissions'], 'coiffure' => ['commissions'], 'autre' => ['stock', 'fournisseurs', 'commandes', 'rapports_gestion', 'equipements']];
        // Options actives par défaut mais désactivables par la boutique (domaine « Autre »).
        $optionalOn = ['autre' => ['produits', 'services', 'clients', 'categories', 'depenses']];

        $domains = [
            'alimentation_generale' => ['Alimentation générale', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports', 'creation_vocale', 'import_produits', 'depenses', 'rapports_gestion']],
            'boucherie' => ['Boucherie', ['produits', 'categories', 'stock', 'decoupe', 'preparations', 'conservation', 'depenses', 'equipements', 'commandes', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports', 'rapports_gestion', 'creation_vocale', 'import_produits']],
            'restaurant' => ['Restaurant', ['produits', 'categories', 'stock', 'conservation', 'recettes', 'tables', 'reservations', 'commandes', 'supplements', 'cuisine', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'depenses', 'equipements', 'rapports', 'rapports_gestion']],
            'coiffure' => ['Coiffure', ['clients', 'services', 'rendez_vous', 'employes', 'ventes', 'caisse', 'rapports', 'produits', 'categories', 'stock', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion', 'promotions', 'consommables', 'forfaits', 'fidelite']],
            'salon_beaute' => ['Salon de beauté', ['clients', 'services', 'rendez_vous', 'employes', 'ventes', 'caisse', 'rapports', 'produits', 'categories', 'stock', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion', 'promotions', 'consommables', 'forfaits', 'fidelite']],
            'pharmacie' => ['Pharmacie', ['produits', 'categories', 'stock', 'conservation', 'lots_stricts', 'conditionnement', 'ventes', 'retours', 'promotions', 'caisse', 'clients', 'ordonnances', 'fournisseurs', 'commandes_fournisseurs', 'employes', 'depenses', 'rapports', 'rapports_gestion']],
            'vetements' => ['Boutique de vêtements', ['produits', 'categories', 'stock', 'variantes', 'collections', 'promotions', 'marques_modeles', 'ventes', 'retours', 'caisse', 'clients', 'fournisseurs', 'employes', 'depenses', 'rapports', 'rapports_gestion', 'creation_vocale', 'import_produits']],
            'electronique' => ['Électronique', ['produits', 'categories', 'stock', 'ventes', 'caisse', 'clients', 'fournisseurs', 'employes', 'rapports', 'creation_vocale', 'import_produits', 'marques_modeles', 'appareils', 'garanties', 'retours', 'depenses', 'rapports_gestion']],
            'atelier' => ['Atelier', ['services', 'categories', 'produits', 'stock', 'reparations', 'devis', 'commandes', 'clients', 'fournisseurs', 'employes', 'ventes', 'caisse', 'depenses', 'equipements', 'rapports', 'rapports_gestion']],
            'pressing' => ['Pressing', ['services', 'commandes', 'clients', 'employes', 'ventes', 'caisse', 'rapports', 'produits', 'categories', 'stock', 'fournisseurs', 'depenses', 'equipements', 'rapports_gestion', 'consommables', 'articles_depot']],
            'autre' => ['Autre', ['produits', 'services', 'ventes', 'caisse', 'clients', 'rapports']],
        ];

        foreach ($domains as $slug => [$name, $featureSlugs]) {
            $domain = BusinessDomain::query()->firstOrCreate(['slug' => $slug], ['nom' => $name]);
            foreach ($optional[$slug] ?? [] as $featureSlug) {
                $domain->domainFeatures()->firstOrCreate(['fonctionnalite_id' => $features[$featureSlug]->id], ['active_par_defaut' => false, 'optionnelle' => true]);
            }
            foreach ($optionalOn[$slug] ?? [] as $featureSlug) {
                $domain->domainFeatures()->updateOrCreate(['fonctionnalite_id' => $features[$featureSlug]->id], ['active_par_defaut' => true, 'optionnelle' => true]);
            }
            if ($domain->vocabulaire === null && isset(FeatureCatalog::VOCABULARY[$slug])) {
                $domain->update(['vocabulaire' => FeatureCatalog::VOCABULARY[$slug]]);
            }

            foreach ($featureSlugs as $featureSlug) {
                $domain->domainFeatures()->firstOrCreate(
                    ['fonctionnalite_id' => $features[$featureSlug]->id],
                    ['active_par_defaut' => true],
                );
            }
        }
    }
}
