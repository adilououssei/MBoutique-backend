<?php

namespace App\Modules\Features\Services;

use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\FeatureDependency;
use App\Modules\Features\Models\StoreFeatureOverride;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Collection;

/**
 * Answers exactly one question: "is this Feature switched on for this
 * Store, right now?" — never "is this user allowed to use it" (that's
 * Authorization/spatie, a completely separate concern, see
 * docs/feature-gate.md §"Feature vs Permission").
 *
 * Resolution order (documented in detail in docs/feature-gate.md, decided
 * during Phase 2 where the design docs left it ambiguous):
 *
 *   1. Feature must exist and be globally active (Feature.actif).
 *   2. Subscription/Plan gate: if the Business's Plan lists ANY features,
 *      this one must be among them (an empty list = no plan restriction).
 *   3. Store's own word: StoreFeatureOverride if one exists, otherwise
 *      the BusinessDomain's default (DomainFeature.active_par_defaut),
 *      otherwise false (fail closed).
 *   4. Every dependency (FeatureDependency) must ALSO resolve to true.
 *
 * BusinessDomain.actif is deliberately NOT part of this runtime
 * check — see docs/feature-gate.md for why (it only gates domain
 * selection when a Store is created/changed, so deactivating a domain
 * later never silently breaks stores already using it).
 */
class FeatureGate
{
    public function allows(Store $store, string $featureSlug): bool
    {
        return (bool) $this->resolveForStore($store)->get($featureSlug, false);
    }

    /**
     * @return Collection<string, bool> feature slug => resolved boolean, for every globally active feature
     */
    public function resolveForStore(Store $store): Collection
    {
        $features = Feature::where('actif', true)->get()->keyBy('id');

        $domainFeatures = DomainFeature::where('domaine_activite_id', $store->domaine_activite_id)
            ->get()
            ->keyBy('fonctionnalite_id');

        $overrides = StoreFeatureOverride::where('boutique_id', $store->id)
            ->get()
            ->keyBy('fonctionnalite_id');

        $dependencies = FeatureDependency::whereIn('fonctionnalite_id', $features->keys())
            ->get()
            ->groupBy('fonctionnalite_id');

        $planFeatureIds = $this->planFeatureIds($store);

        $resolved = [];
        $resolving = [];

        $resolve = function (int $featureId) use (&$resolve, &$resolved, &$resolving, $features, $domainFeatures, $overrides, $dependencies, $planFeatureIds): bool {
            if (array_key_exists($featureId, $resolved)) {
                return $resolved[$featureId];
            }

            // Defensive cycle guard: FeatureDependency seed data controls
            // this, but a cycle must fail closed, never loop forever.
            if (isset($resolving[$featureId])) {
                return $resolved[$featureId] = false;
            }
            $resolving[$featureId] = true;

            if (! $features->has($featureId)) {
                return $resolved[$featureId] = false;
            }

            if ($planFeatureIds !== null && ! in_array($featureId, $planFeatureIds, true)) {
                return $resolved[$featureId] = false;
            }

            $override = $overrides->get($featureId);
            $base = $override !== null
                ? $override->activee
                : (bool) ($domainFeatures->get($featureId)?->active_par_defaut ?? false);

            if (! $base) {
                return $resolved[$featureId] = false;
            }

            foreach ($dependencies->get($featureId, collect()) as $dependency) {
                if (! $resolve($dependency->depend_de_fonctionnalite_id)) {
                    return $resolved[$featureId] = false;
                }
            }

            return $resolved[$featureId] = true;
        };

        return $features->mapWithKeys(fn (Feature $feature) => [$feature->slug => $resolve($feature->id)]);
    }

    /**
     * @return array<int>|null null = no plan-level restriction (no subscription yet, or the plan doesn't restrict by feature)
     */
    private function planFeatureIds(Store $store): ?array
    {
        $subscription = $store->business->subscription;

        if (! $subscription) {
            return null;
        }

        $planFeatureIds = $subscription->plan->features()->pluck('fonctionnalites.id')->all();

        return $planFeatureIds === [] ? null : $planFeatureIds;
    }
}
