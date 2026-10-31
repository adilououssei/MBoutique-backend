<?php

namespace App\Modules\Admin\Support;

use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\FeatureDependency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attribution des fonctionnalités aux métiers depuis l'admin, en respectant
 * les dépendances : activer une fonctionnalité active aussi ce dont elle a
 * besoin (« Découpe » → « Stock », « Produits »). Le retrait est immédiat :
 * FeatureGate ne met rien en cache, l'application le voit au prochain
 * rafraîchissement de sa liste de fonctionnalités.
 */
final class DomainFeatureAssignment
{
    /** @var Collection<int, Collection<int, int>> fonctionnalite_id => ids dont elle dépend */
    private Collection $dependencies;

    public function __construct()
    {
        $this->dependencies = FeatureDependency::query()->get()->groupBy('fonctionnalite_id')
            ->map(fn (Collection $rows) => $rows->pluck('depend_de_fonctionnalite_id'));
    }

    /**
     * Ajoute récursivement les dépendances manquantes.
     *
     * @param  array<int, int>  $featureIds
     * @return array{0: array<int, int>, 1: array<int, int>} [ensemble complet, ids ajoutés automatiquement]
     */
    public function withDependencies(array $featureIds): array
    {
        $set = array_values(array_unique(array_map('intval', $featureIds)));
        $added = [];
        for ($i = 0; $i < count($set); $i++) {
            foreach ($this->dependencies->get($set[$i], collect()) as $dependency) {
                if (! in_array($dependency, $set, true)) {
                    $set[] = $dependency;
                    $added[] = $dependency;
                }
            }
        }

        return [$set, $added];
    }

    /**
     * Remplace la configuration d'un métier : fonctionnalités incluses
     * (actives par défaut) et en option (chaque boutique choisit). Une
     * fonctionnalité qui n'est plus proposée perd aussi les choix des
     * boutiques : elle disparaît, sans exception.
     *
     * @param  array<int, int>  $included
     * @param  array<int, int>  $optional
     */
    public function syncDomain(int $domainId, array $included, array $optional = []): void
    {
        $optional = array_values(array_diff($optional, $included));
        DB::transaction(function () use ($domainId, $included, $optional) {
            foreach ($included as $featureId) {
                DomainFeature::query()->updateOrCreate(['domaine_activite_id' => $domainId, 'fonctionnalite_id' => $featureId], ['active_par_defaut' => true, 'optionnelle' => false]);
            }
            foreach ($optional as $featureId) {
                DomainFeature::query()->updateOrCreate(['domaine_activite_id' => $domainId, 'fonctionnalite_id' => $featureId], ['active_par_defaut' => false, 'optionnelle' => true]);
            }
            $offered = [...$included, ...$optional];
            DomainFeature::query()->where('domaine_activite_id', $domainId)->whereNotIn('fonctionnalite_id', $offered)->update(['active_par_defaut' => false, 'optionnelle' => false]);
            DB::table('fonctionnalites_boutique')
                ->whereIn('boutique_id', DB::table('boutiques')->where('domaine_activite_id', $domainId)->select('id'))
                ->whereNotIn('fonctionnalite_id', $offered)
                ->delete();
        });
    }

    /**
     * Lit l'état demandé par un formulaire : liste d'ids (= incluses, format
     * historique) ou { id: 'incluse' | 'option' | '' }.
     *
     * @return array{0: array<int, int>, 1: array<int, int>} [incluses, en option]
     */
    public static function parseStates(array $input): array
    {
        if (array_is_list($input)) {
            return [array_map('intval', $input), []];
        }
        $included = $optional = [];
        foreach ($input as $id => $state) {
            if ($state === 'incluse') {
                $included[] = (int) $id;
            } elseif ($state === 'option') {
                $optional[] = (int) $id;
            }
        }

        return [$included, $optional];
    }

    /** Fonctionnalités qui dépendent (directement) de chacune : pour l'interface. */
    public function dependents(): Collection
    {
        $dependents = collect();
        foreach ($this->dependencies as $featureId => $needs) {
            foreach ($needs as $need) {
                $dependents[$need] = collect($dependents->get($need, []))->push($featureId)->all();
            }
        }

        return $dependents;
    }

    public function dependenciesMap(): Collection
    {
        return $this->dependencies;
    }
}
