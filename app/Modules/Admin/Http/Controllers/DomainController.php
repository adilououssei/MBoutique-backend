<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\DomainFeatureAssignment;
use App\Modules\Catalog\Enums\ProductUnit;
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
 * Domaines d'activité (boutique, salon, restaurant…), les fonctionnalités
 * activées pour chacun — classées par type d'activité — et le vocabulaire du
 * métier affiché dans l'application. docs/feature-gate.md.
 */
class DomainController extends Controller
{
    public function index(): View
    {
        return view('admin.domaines.index', [
            'domains' => BusinessDomain::query()
                ->withCount(['stores', 'domainFeatures as fonctionnalites_count' => fn ($q) => $q->where('active_par_defaut', true)])
                ->orderBy('nom')
                ->get(),
            'featureCount' => Feature::count(),
        ]);
    }

    public function show(BusinessDomain $domain, DomainFeatureAssignment $assignment): View
    {
        $features = Feature::query()->orderBy('nom')->get();
        // Les autres métiers qui utilisent chaque fonctionnalité.
        $usedBy = DomainFeature::query()
            ->where('active_par_defaut', true)
            ->where('domaine_activite_id', '!=', $domain->id)
            ->join('domaines_activite', 'domaines_activite.id', '=', 'fonctionnalites_domaine.domaine_activite_id')
            ->get(['fonctionnalites_domaine.fonctionnalite_id', 'domaines_activite.nom'])
            ->groupBy('fonctionnalite_id')
            ->map(fn ($rows) => $rows->pluck('nom')->sort()->values());

        return view('admin.domaines.show', [
            'domain' => $domain->loadCount('stores'),
            'groups' => $features->groupBy(fn (Feature $f) => array_key_exists($f->categorie ?? '', FeatureCatalog::CATEGORIES) ? $f->categorie : 'autres')
                ->sortBy(fn ($items, $category) => array_search($category, array_keys(FeatureCatalog::CATEGORIES), true)),
            'byId' => $features->keyBy('id'),
            'usedBy' => $usedBy,
            'dependencies' => $assignment->dependenciesMap(),
            'dependents' => $assignment->dependents(),
            'states' => $domain->domainFeatures()->get()->mapWithKeys(fn ($df) => [$df->fonctionnalite_id => $df->active_par_defaut ? 'incluse' : ($df->optionnelle ? 'option' : '')])->all(),
            'vocabularyFields' => FeatureCatalog::VOCABULARY_FIELDS,
            'units' => ProductUnit::cases(),
        ]);
    }

    public function update(Request $request, BusinessDomain $domain, DomainFeatureAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'fonctionnalites' => ['array'],
            'fonctionnalites.*' => ['nullable'],
            'vocabulaire' => ['array'],
            'vocabulaire.*' => ['nullable', 'string', 'max:150'],
            'vocabulaire.unite' => ['nullable', Rule::enum(ProductUnit::class)],
        ]);
        [$included, $optional] = DomainFeatureAssignment::parseStates($data['fonctionnalites'] ?? []);
        $known = Feature::query()->pluck('id')->all();
        $included = array_values(array_intersect($included, $known));
        $optional = array_values(array_intersect($optional, $known));
        // Ce dont dépendent les fonctionnalités incluses ou en option est inclus.
        [$all, $added] = $assignment->withDependencies([...$included, ...$optional]);
        $included = array_values(array_unique([...$included, ...$added]));
        $vocabulary = array_filter(array_intersect_key($data['vocabulaire'] ?? [], FeatureCatalog::VOCABULARY_FIELDS), fn ($v) => filled($v));

        DB::transaction(function () use ($domain, $data, $included, $optional, $vocabulary, $request, $assignment) {
            $domain->forceFill([
                'nom' => $data['nom'],
                'description' => $data['description'] ?? null,
                'actif' => $request->boolean('actif'),
                'vocabulaire' => $vocabulary ?: null,
            ])->save();
            $assignment->syncDomain($domain->id, $included, $optional);
        });

        $message = "Domaine « {$domain->nom} » mis à jour. Les boutiques de ce métier voient le changement dès que l'application se rafraîchit.";
        if ($added !== []) {
            $message .= ' Ajouté(es) car nécessaire(s) : '.Feature::query()->whereIn('id', $added)->orderBy('nom')->pluck('nom')->implode(', ').'.';
        }

        return back()->with('succes', $message);
    }
}
