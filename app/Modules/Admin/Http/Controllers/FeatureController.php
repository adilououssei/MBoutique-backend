<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\DomainFeatureAssignment;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Fonctionnalités vues sous l'angle inverse des domaines : à quels métiers
 * chacune est réservée, et l'ajouter (ou la retirer) à d'autres métiers.
 */
class FeatureController extends Controller
{
    public function index(): View
    {
        $features = Feature::query()->orderBy('nom')->get();
        $domainsByFeature = DomainFeature::query()
            ->where('active_par_defaut', true)
            ->join('domaines_activite', 'domaines_activite.id', '=', 'fonctionnalites_domaine.domaine_activite_id')
            ->get(['fonctionnalites_domaine.fonctionnalite_id', 'domaines_activite.nom'])
            ->groupBy('fonctionnalite_id')
            ->map(fn ($rows) => $rows->pluck('nom')->sort()->values());

        return view('admin.fonctionnalites.index', [
            'groups' => $features->groupBy(fn (Feature $f) => array_key_exists($f->categorie ?? '', FeatureCatalog::CATEGORIES) ? $f->categorie : 'autres')
                ->sortBy(fn ($items, $category) => array_search($category, array_keys(FeatureCatalog::CATEGORIES), true)),
            'domainsByFeature' => $domainsByFeature,
            'domainCount' => BusinessDomain::count(),
        ]);
    }

    public function edit(Feature $feature, DomainFeatureAssignment $assignment): View
    {
        $byId = Feature::query()->get()->keyBy('id');

        return view('admin.fonctionnalites.edit', [
            'feature' => $feature,
            'domains' => BusinessDomain::query()->withCount('stores')->orderBy('nom')->get(),
            'domainStates' => DomainFeature::query()->where('fonctionnalite_id', $feature->id)->get()->mapWithKeys(fn ($df) => [$df->domaine_activite_id => $df->active_par_defaut ? 'incluse' : ($df->optionnelle ? 'option' : '')])->all(),
            'needs' => $assignment->dependenciesMap()->get($feature->id, collect())->map(fn ($id) => $byId->get($id)?->nom)->filter()->values(),
            'neededBy' => collect($assignment->dependents()->get($feature->id, []))->map(fn ($id) => $byId->get($id)?->nom)->filter()->values(),
            'categories' => FeatureCatalog::CATEGORIES,
        ]);
    }

    /**
     * Ajouter la fonctionnalité à un métier lui ajoute aussi ses dépendances ;
     * la retirer d'un métier en retire aussi ce qui en dépend (sinon ces
     * fonctionnalités resteraient affichées mais inutilisables).
     */
    public function update(Request $request, Feature $feature, DomainFeatureAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'categorie' => ['required', Rule::in(array_keys(FeatureCatalog::CATEGORIES))],
            'domaines' => ['array'],
            'domaines.*' => ['nullable'],
        ]);
        [$selectedIncluded, $selectedOptional] = DomainFeatureAssignment::parseStates($data['domaines'] ?? []);
        $dependents = $this->dependentsClosure($feature->id, $assignment);

        DB::transaction(function () use ($feature, $data, $request, $selectedIncluded, $selectedOptional, $dependents, $assignment) {
            $feature->forceFill(['nom' => $data['nom'], 'description' => $data['description'] ?? null, 'categorie' => $data['categorie'], 'actif' => $request->boolean('actif')])->save();

            foreach (BusinessDomain::query()->pluck('id') as $domainId) {
                $rows = DomainFeature::query()->where('domaine_activite_id', $domainId)->get();
                $included = $rows->where('active_par_defaut', true)->pluck('fonctionnalite_id')->all();
                $optional = $rows->where('optionnelle', true)->pluck('fonctionnalite_id')->all();
                $without = fn (array $ids) => array_values(array_diff($ids, [$feature->id]));

                if (in_array($domainId, $selectedIncluded, true)) {
                    $included = [...$without($included), $feature->id];
                    $optional = $without($optional);
                } elseif (in_array($domainId, $selectedOptional, true)) {
                    $optional = [...$without($optional), $feature->id];
                    $included = $without($included);
                } else {
                    // Retirée de ce métier : ce qui en dépend aussi.
                    $included = array_values(array_diff($included, [$feature->id, ...$dependents]));
                    $optional = array_values(array_diff($optional, [$feature->id, ...$dependents]));
                }
                [, $added] = $assignment->withDependencies([...$included, ...$optional]);
                $assignment->syncDomain($domainId, array_values(array_unique([...$included, ...$added])), $optional);
            }
        });

        return back()->with('succes', "« {$feature->nom} » mise à jour. Les boutiques concernées voient le changement dès que l'application se rafraîchit.");
    }

    /** @return array<int, int> toutes les fonctionnalités qui dépendent, même indirectement, de celle-ci */
    private function dependentsClosure(int $featureId, DomainFeatureAssignment $assignment): array
    {
        $dependents = $assignment->dependents();
        $found = [];
        $queue = [$featureId];
        while ($queue !== []) {
            foreach ($dependents->get(array_shift($queue), []) as $id) {
                if (! in_array($id, $found, true)) {
                    $found[] = $id;
                    $queue[] = $id;
                }
            }
        }

        return $found;
    }
}
