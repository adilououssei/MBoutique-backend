<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Features\Models\Feature;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Grille tarifaire : prix, quotas (vide = illimité) et fonctionnalités
 * incluses (aucune cochée = pas de restriction par forfait, docs/subscriptions.md).
 */
class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.forfaits.index', [
            'plans' => Plan::query()->withCount(['features', 'subscriptions'])->orderBy('prix_mensuel')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.forfaits.form', ['plan' => new Plan, 'features' => Feature::query()->orderBy('nom')->get(), 'selected' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $plan = Plan::create($data);
            $plan->forceFill(['actif' => $data['actif']])->save();
            $plan->features()->sync($data['fonctionnalites']);
        });

        return redirect()->route('admin.forfaits.index')->with('succes', "Forfait « {$data['nom']} » créé.");
    }

    public function edit(Plan $plan): View
    {
        return view('admin.forfaits.form', [
            'plan' => $plan,
            'features' => Feature::query()->orderBy('nom')->get(),
            'selected' => $plan->features()->pluck('fonctionnalites.id')->all(),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validated($request, $plan);

        DB::transaction(function () use ($plan, $data) {
            $plan->fill($data)->forceFill(['actif' => $data['actif']])->save();
            $plan->features()->sync($data['fonctionnalites']);
        });

        return redirect()->route('admin.forfaits.index')->with('succes', "Forfait « {$plan->nom} » mis à jour.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('forfaits', 'code')->ignore($plan)],
            'nom' => ['required', 'string', 'max:100'],
            'prix_mensuel' => ['required', 'numeric', 'min:0'],
            'prix_annuel' => ['nullable', 'numeric', 'min:0'],
            'max_boutiques' => ['nullable', 'integer', 'min:1'],
            'max_utilisateurs_par_boutique' => ['nullable', 'integer', 'min:1'],
            'max_produits_par_boutique' => ['nullable', 'integer', 'min:1'],
            'fonctionnalites' => ['array'],
            'fonctionnalites.*' => ['integer', 'exists:fonctionnalites,id'],
        ], [], [
            'prix_mensuel' => 'prix mensuel',
            'prix_annuel' => 'prix annuel',
            'max_boutiques' => 'boutiques maximum',
            'max_utilisateurs_par_boutique' => 'utilisateurs par boutique',
            'max_produits_par_boutique' => 'produits par boutique',
        ]);

        return [...$data, 'actif' => $request->boolean('actif'), 'fonctionnalites' => $data['fonctionnalites'] ?? []];
    }
}
