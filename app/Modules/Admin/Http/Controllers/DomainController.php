<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Domaines d'activité (boutique, salon, restaurant…) et les fonctionnalités
 * activées par défaut pour chacun. Une boutique peut ensuite surcharger
 * ce défaut (StoreFeatureOverride) — docs/feature-gate.md.
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

    public function show(BusinessDomain $domain): View
    {
        return view('admin.domaines.show', [
            'domain' => $domain->loadCount('stores'),
            'features' => Feature::query()->with('dependencies')->orderBy('nom')->get(),
            'enabled' => $domain->domainFeatures()->where('active_par_defaut', true)->pluck('fonctionnalite_id')->all(),
        ]);
    }

    public function update(Request $request, BusinessDomain $domain): RedirectResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'fonctionnalites' => ['array'],
            'fonctionnalites.*' => ['integer', 'exists:fonctionnalites,id'],
        ]);
        $enabled = array_map('intval', $data['fonctionnalites'] ?? []);

        DB::transaction(function () use ($domain, $data, $enabled, $request) {
            $domain->forceFill(['nom' => $data['nom'], 'description' => $data['description'] ?? null, 'actif' => $request->boolean('actif')])->save();

            foreach ($enabled as $featureId) {
                DomainFeature::query()->updateOrCreate(['domaine_activite_id' => $domain->id, 'fonctionnalite_id' => $featureId], ['active_par_defaut' => true]);
            }
            DomainFeature::query()
                ->where('domaine_activite_id', $domain->id)
                ->whereNotIn('fonctionnalite_id', $enabled)
                ->update(['active_par_defaut' => false]);
        });

        return back()->with('succes', "Domaine « {$domain->nom} » mis à jour.");
    }
}
