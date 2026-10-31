<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Support\Countries;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Enums\BusinessStatus;
use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Vue d'ensemble de la plateforme. Les ventes sont agrégées toutes boutiques
 * confondues (withoutStoreScope : lecture plateforme explicite).
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $since = now()->subDays(30);
        $sales = Sale::withoutStoreScope()->where('statut', SaleStatus::Completed)->where('vendue_le', '>=', $since);

        // Inscriptions (entreprises créées) sur les 6 derniers mois.
        $months = collect(range(5, 0))->map(fn (int $ago) => now()->startOfMonth()->subMonths($ago));
        $signups = Business::query()
            ->where('created_at', '>=', $months->first())
            ->get(['created_at'])
            ->groupBy(fn (Business $b) => $b->created_at->format('Y-m'));
        $chart = $months->map(fn (Carbon $month) => [
            'libelle' => ucfirst($month->locale('fr')->translatedFormat('M')),
            'valeur' => $signups->get($month->format('Y-m'))?->count() ?? 0,
        ]);

        return view('admin.tableau-de-bord', [
            'stats' => [
                'entreprises' => Business::count(),
                'entreprises_suspendues' => Business::where('statut', BusinessStatus::Suspended)->count(),
                'boutiques' => Store::count(),
                'utilisateurs' => User::count(),
                'nouveaux_utilisateurs' => User::where('created_at', '>=', $since)->count(),
                'ventes' => (clone $sales)->count(),
                'chiffre_affaires' => (string) (clone $sales)->sum('montant_total'),
                'abonnements' => Subscription::query()->selectRaw('statut, count(*) as total')->groupBy('statut')->pluck('total', 'statut'),
            ],
            'chart' => $chart,
            'chartMax' => max(1, $chart->max('valeur')),
            'domains' => BusinessDomain::query()->withCount('stores')->orderByDesc('stores_count')->limit(6)->get(),
            'countries' => $this->countries($since),
            'latest' => Business::query()->with(['owner', 'subscription.plan'])->withCount('stores')->latest()->limit(6)->get(),
        ]);
    }

    /**
     * Par pays déclaré à l'inscription : comptes, nouveaux comptes et comptes
     * actifs (appli utilisée) sur 30 jours, entreprises (pays du propriétaire).
     *
     * @return Collection<int, array{code: string|null, nom: string, utilisateurs: int, nouveaux: int, actifs: int, entreprises: int}>
     */
    private function countries(Carbon $since): Collection
    {
        $users = User::query()->selectRaw('pays, COUNT(*) as total')->groupBy('pays')->pluck('total', 'pays');
        $newcomers = User::query()->where('created_at', '>=', $since)->selectRaw('pays, COUNT(*) as total')->groupBy('pays')->pluck('total', 'pays');
        $active = User::query()->whereHas('tokens', fn ($q) => $q->where('last_used_at', '>=', $since))
            ->selectRaw('pays, COUNT(*) as total')->groupBy('pays')->pluck('total', 'pays');
        $businesses = Business::query()->join('utilisateurs', 'utilisateurs.id', '=', (new Business)->getTable().'.proprietaire_id')
            ->selectRaw('utilisateurs.pays as pays, COUNT(*) as total')->groupBy('utilisateurs.pays')->pluck('total', 'pays');

        return $users->map(fn ($total, $code) => [
            'code' => $code ?: null,
            'nom' => $code ? Countries::name($code) : 'Non renseigné',
            'utilisateurs' => (int) $total,
            'nouveaux' => (int) ($newcomers[$code] ?? 0),
            'actifs' => (int) ($active[$code] ?? 0),
            'entreprises' => (int) ($businesses[$code] ?? 0),
        ])->sortByDesc(fn (array $row) => [$row['code'] !== null, $row['utilisateurs']])->values();
    }
}
