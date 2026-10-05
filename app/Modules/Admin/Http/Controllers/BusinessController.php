<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Tenancy\Enums\BusinessStatus;
use App\Modules\Tenancy\Models\Business;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Entreprises clientes : consultation, suspension, abonnement (géré à la main, sans paiement). */
class BusinessController extends Controller
{
    public function index(Request $request): View
    {
        $businesses = Business::query()
            ->with(['owner', 'subscription.plan'])
            ->withCount('stores')
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('nom', 'like', $term)
                    ->orWhere('raison_sociale', 'like', $term)
                    ->orWhereHas('owner', fn ($q) => $q->where('email', 'like', $term)->orWhere('nom', 'like', $term)));
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.entreprises.index', ['businesses' => $businesses]);
    }

    public function show(Business $business): View
    {
        $business->load(['owner', 'subscription.plan', 'businessUsers.user', 'stores.businessDomain']);
        $business->stores->loadCount('storeUsers');

        // Activité des 30 derniers jours, par boutique.
        $activity = Sale::withoutStoreScope()
            ->whereIn('boutique_id', $business->stores->pluck('id'))
            ->where('statut', SaleStatus::Completed)
            ->where('vendue_le', '>=', now()->subDays(30))
            ->selectRaw('boutique_id, count(*) as ventes, sum(montant_total) as chiffre_affaires')
            ->groupBy('boutique_id')
            ->get()
            ->keyBy('boutique_id');

        return view('admin.entreprises.show', [
            'business' => $business,
            'activity' => $activity,
            'plans' => Plan::query()->orderBy('prix_mensuel')->get(),
            'subscriptionStatuses' => SubscriptionStatus::cases(),
        ]);
    }

    public function updateStatus(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate(['statut' => ['required', Rule::enum(BusinessStatus::class)]]);
        $business->forceFill(['statut' => $data['statut']])->save();

        return back()->with('succes', $business->statut === BusinessStatus::Suspended
            ? "« {$business->nom} » est suspendue : ses boutiques ne sont plus accessibles depuis l'application."
            : "« {$business->nom} » est de nouveau active.");
    }

    public function updateSubscription(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'forfait_id' => ['required', 'integer', 'exists:forfaits,id'],
            'statut' => ['required', Rule::enum(SubscriptionStatus::class)],
            'fin_essai_le' => ['nullable', 'date'],
            'fin_periode_le' => ['nullable', 'date'],
        ], [], ['forfait_id' => 'forfait', 'fin_essai_le' => "fin d'essai", 'fin_periode_le' => 'fin de période']);

        $current = $business->subscription;
        $business->subscription()->updateOrCreate([], [
            ...$data,
            'debut_periode_le' => $current?->debut_periode_le ?? now(),
            'annule_le' => $data['statut'] === SubscriptionStatus::Cancelled->value ? ($current?->annule_le ?? now()) : null,
        ]);

        return back()->with('succes', 'Abonnement mis à jour.');
    }
}
