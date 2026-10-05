@extends('admin.layouts.app')
@php
    use App\Modules\Admin\Support\Format;
    use App\Modules\Tenancy\Enums\BusinessStatus;
    [$statusLabel, $statusTone] = Format::businessStatus($business->statut);
    $subscription = $business->subscription;
    [$subLabel, $subTone] = Format::subscriptionStatus($subscription?->statut);
    $suspended = $business->statut === BusinessStatus::Suspended;
@endphp

@section('title', $business->nom)
@section('crumbs')
    <a href="{{ route('admin.entreprises.index') }}">Entreprises</a> <x-admin.icon name="chevron-forward" /> {{ $business->nom }}
@endsection
@section('actions')
    <form method="POST" action="{{ route('admin.entreprises.statut', $business) }}"
          data-confirm="{{ $suspended ? 'Réactiver cette entreprise ?' : 'Suspendre cette entreprise ? Ses boutiques ne seront plus accessibles depuis l\'application.' }}">
        @csrf @method('PATCH')
        <input type="hidden" name="statut" value="{{ $suspended ? 'active' : 'suspendue' }}">
        @if ($suspended)
            <button class="btn btn-success" type="submit"><x-admin.icon name="play-circle-outline" />Réactiver</button>
        @else
            <button class="btn btn-dark" type="submit"><x-admin.icon name="pause-circle-outline" />Suspendre</button>
        @endif
    </form>
@endsection

@section('content')
    @if ($suspended)
        <div class="alert alert-warning"><x-admin.icon name="warning" />Entreprise suspendue : ses membres voient « Cette boutique est suspendue » dans l'application.</div>
    @endif

    <div class="grid grid-main">
        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card card-pad">
                <div class="flex" style="gap:16px">
                    <span class="avatar lg square">{{ Format::initials($business->nom) }}</span>
                    <div style="flex:1">
                        <div class="strong" style="font-size:20px">{{ $business->nom }}</div>
                        <div class="muted">{{ $business->raison_sociale ?: 'Raison sociale non renseignée' }}</div>
                    </div>
                    <x-admin.badge :tone="$statusTone">{{ $statusLabel }}</x-admin.badge>
                </div>
                <div class="info-list" style="margin-top:14px">
                    <div class="info-row"><span class="k">Propriétaire</span><span class="v">{{ $business->owner?->nom }} · {{ $business->owner?->email }}</span></div>
                    <div class="info-row"><span class="k">Pays</span><span class="v">{{ $business->pays ?: '—' }}</span></div>
                    <div class="info-row"><span class="k">Devise · fuseau</span><span class="v">{{ $business->devise }} · {{ $business->fuseau_horaire }}</span></div>
                    <div class="info-row"><span class="k">Inscrite le</span><span class="v">{{ $business->created_at?->locale('fr')->isoFormat('D MMMM YYYY') }}</span></div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Boutiques</h2><span class="sub">{{ $business->stores->count() }}</span></div>
                @if ($business->stores->isEmpty())
                    <x-admin.empty icon="storefront-outline" title="Aucune boutique" />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Boutique</th><th>Métier</th><th>Membres</th><th class="num">Ventes 30 j</th><th class="num">CA 30 j</th><th>Statut</th></tr></thead>
                            <tbody>
                            @foreach ($business->stores as $store)
                                @php [$sl, $st] = Format::storeStatus($store->statut); $a = $activity->get($store->id); @endphp
                                <tr>
                                    <td><div class="cell-title">{{ $store->nom }}</div><div class="cell-sub">{{ $store->telephone ?: $store->adresse ?: '—' }}</div></td>
                                    <td>{{ $store->businessDomain?->nom ?? '—' }}</td>
                                    <td>{{ $store->store_users_count }}</td>
                                    <td class="num">{{ Format::number($a?->ventes ?? 0) }}</td>
                                    <td class="num strong">{{ Format::money($a?->chiffre_affaires ?? 0, $store->devise ?: 'FCFA') }}</td>
                                    <td><x-admin.badge :tone="$st">{{ $sl }}</x-admin.badge></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-head"><h2>Équipe de l'entreprise</h2></div>
                @foreach ($business->businessUsers as $member)
                    <div class="list-item">
                        <span class="avatar">{{ Format::initials($member->user?->nom) }}</span>
                        <div style="flex:1"><div class="cell-title">{{ $member->user?->nom }}</div><div class="cell-sub">{{ $member->user?->email }}</div></div>
                        <x-admin.badge tone="info">{{ ucfirst($member->role?->value ?? (string) $member->role) }}</x-admin.badge>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <span class="icon-circle tone-primary"><x-admin.icon name="card" /></span>
                <div><h2>Abonnement</h2><div class="sub">Géré manuellement</div></div>
                <div class="right"><x-admin.badge :tone="$subTone">{{ $subLabel }}</x-admin.badge></div>
            </div>
            <form class="card-pad form" method="POST" action="{{ route('admin.entreprises.abonnement', $business) }}">
                @csrf @method('PUT')
                @if ($plans->isEmpty())
                    <div class="alert alert-warning"><x-admin.icon name="information-circle" />Aucun forfait : créez-en un d'abord.</div>
                    <a class="btn btn-outline" href="{{ route('admin.forfaits.create') }}"><x-admin.icon name="add" />Créer un forfait</a>
                @else
                    <x-admin.field name="forfait_id" label="Forfait" required>
                        <select class="input" id="forfait_id" name="forfait_id">
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->id }}" @selected(old('forfait_id', $subscription?->forfait_id) == $plan->id)>{{ $plan->nom }} — {{ Format::money($plan->prix_mensuel) }}/mois{{ $plan->actif ? '' : ' (inactif)' }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field name="statut" label="Statut" required>
                        <select class="input" id="statut" name="statut">
                            @foreach ($subscriptionStatuses as $status)
                                <option value="{{ $status->value }}" @selected(old('statut', $subscription?->statut?->value ?? 'actif') === $status->value)>{{ Format::subscriptionStatus($status)[0] }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field name="fin_essai_le" label="Fin de l'essai">
                        <input class="input" type="date" id="fin_essai_le" name="fin_essai_le" value="{{ old('fin_essai_le', $subscription?->fin_essai_le?->format('Y-m-d')) }}">
                    </x-admin.field>
                    <x-admin.field name="fin_periode_le" label="Fin de la période payée" help="Date jusqu'à laquelle l'abonnement est réglé.">
                        <input class="input" type="date" id="fin_periode_le" name="fin_periode_le" value="{{ old('fin_periode_le', $subscription?->fin_periode_le?->format('Y-m-d')) }}">
                    </x-admin.field>
                    @if ($subscription)
                        <div class="info-list">
                            <div class="info-row"><span class="k">Début de période</span><span class="v">{{ $subscription->debut_periode_le?->locale('fr')->isoFormat('D MMM YYYY') ?? '—' }}</span></div>
                            <div class="info-row"><span class="k">Boutiques</span><span class="v">{{ $business->stores->count() }} / {{ $subscription->plan?->max_boutiques ?? '∞' }}</span></div>
                        </div>
                    @endif
                    <button class="btn btn-block" type="submit"><x-admin.icon name="save-outline" />Enregistrer l'abonnement</button>
                @endif
            </form>
        </div>
    </div>
@endsection
