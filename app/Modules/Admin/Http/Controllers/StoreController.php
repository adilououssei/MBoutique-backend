<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Enums\StoreStatus;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Toutes les boutiques de la plateforme. */
class StoreController extends Controller
{
    public function index(Request $request): View
    {
        $stores = Store::query()
            ->with(['business', 'businessDomain'])
            ->withCount('storeUsers')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('nom', 'like', $term)
                    ->orWhere('telephone', 'like', $term)
                    ->orWhereHas('business', fn ($q) => $q->where('nom', 'like', $term)));
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->filled('domaine'), fn ($q) => $q->where('domaine_activite_id', $request->integer('domaine')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.boutiques.index', ['stores' => $stores]);
    }

    public function updateStatus(Request $request, Store $store): RedirectResponse
    {
        $data = $request->validate(['statut' => ['required', Rule::enum(StoreStatus::class)]]);
        $store->forceFill(['statut' => $data['statut']])->save();

        return back()->with('succes', $store->statut === StoreStatus::Active
            ? "La boutique « {$store->nom} » est réactivée."
            : "La boutique « {$store->nom} » est désactivée : elle n'est plus accessible depuis l'application.");
    }
}
