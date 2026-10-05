@extends('admin.layouts.app')
@php use App\Modules\Admin\Support\Format; @endphp

@section('title', 'Tableau de bord')
@section('crumbs')
    Bonjour {{ \Illuminate\Support\Str::of(auth()->user()->nom)->explode(' ')->first() }}, voici l'activité de la plateforme.
@endsection

@section('content')
    <div class="grid grid-main stretch">
        <div class="hero">
            <span class="hero-label">Chiffre d'affaires des boutiques · 30 derniers jours</span>
            <span class="hero-value">{{ Format::money($stats['chiffre_affaires']) }}</span>
            <div class="hero-row">
                <span class="hero-pill"><b>{{ Format::number($stats['ventes']) }}</b> ventes encaissées</span>
                <span class="hero-pill"><b>{{ Format::number($stats['boutiques']) }}</b> boutiques</span>
                <span class="hero-pill"><b>{{ Format::number($stats['nouveaux_utilisateurs']) }}</b> nouveaux comptes</span>
            </div>
        </div>
        <div class="card card-pad" style="display:flex;flex-direction:column;gap:14px">
            <div class="flex"><span class="icon-circle tone-primary"><x-admin.icon name="card" /></span><div><div class="strong">Abonnements</div><div class="muted" style="font-size:12px">Gérés manuellement (paiement à venir)</div></div></div>
            @php
                $statuses = [['actif', 'Actifs', 'success'], ['essai', 'En essai', 'info'], ['impaye', 'Impayés', 'warning'], ['annule', 'Annulés', 'danger']];
                $subsTotal = max(1, $stats['abonnements']->sum());
            @endphp
            @foreach ($statuses as [$key, $label, $tone])
                @php $count = (int) ($stats['abonnements'][$key] ?? 0); @endphp
                <div>
                    <div class="flex" style="justify-content:space-between;margin-bottom:6px"><span class="badge tone-{{ $tone }}">{{ $label }}</span><span class="strong">{{ $count }}</span></div>
                    <div class="progress"><span style="width: {{ round($count / $subsTotal * 100) }}%; background: var(--{{ $tone }})"></span></div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid grid-4">
        <x-admin.stat label="Entreprises" :value="Format::number($stats['entreprises'])" icon="business" :foot="$stats['entreprises_suspendues'] ? $stats['entreprises_suspendues'].' suspendue(s)' : 'Toutes actives'" />
        <x-admin.stat label="Boutiques" :value="Format::number($stats['boutiques'])" icon="storefront" tone="success" foot="Tous métiers confondus" />
        <x-admin.stat label="Utilisateurs" :value="Format::number($stats['utilisateurs'])" icon="people" tone="info" :foot="'+'.$stats['nouveaux_utilisateurs'].' sur 30 jours'" />
        <x-admin.stat label="Ventes (30 j)" :value="Format::number($stats['ventes'])" icon="cart" tone="warning" foot="Ventes non annulées" />
    </div>

    <div class="grid grid-main">
        <div class="card">
            <div class="card-head">
                <div><h2>Nouvelles entreprises</h2><div class="sub">Inscriptions des 6 derniers mois</div></div>
            </div>
            <div class="card-pad">
                <div class="bars">
                    @foreach ($chart as $point)
                        <div class="bar">
                            <span class="bar-val">{{ $point['valeur'] }}</span>
                            <span @class(['bar-col', 'zero' => $point['valeur'] === 0]) style="height: {{ max(3, round($point['valeur'] / $chartMax * 100)) }}%"></span>
                            <span class="bar-lbl">{{ $point['libelle'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-head">
                <div><h2>Métiers</h2><div class="sub">Boutiques par domaine d'activité</div></div>
                <div class="right"><a class="row-link" href="{{ route('admin.domaines.index') }}">Voir tout</a></div>
            </div>
            @php $domainMax = max(1, $domains->max('stores_count')); @endphp
            <div class="card-pad" style="display:flex;flex-direction:column;gap:14px">
                @forelse ($domains as $domain)
                    <div>
                        <div class="flex" style="justify-content:space-between;margin-bottom:6px"><span style="font-weight:600">{{ $domain->nom }}</span><span class="strong">{{ $domain->stores_count }}</span></div>
                        <div class="progress"><span style="width: {{ round($domain->stores_count / $domainMax * 100) }}%"></span></div>
                    </div>
                @empty
                    <p class="muted">Aucun domaine d'activité.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Dernières entreprises inscrites</h2></div>
            <div class="right"><a class="btn btn-outline btn-sm" href="{{ route('admin.entreprises.index') }}">Toutes les entreprises <x-admin.icon name="arrow-forward" /></a></div>
        </div>
        @include('admin.entreprises.partials.table', ['businesses' => $latest])
    </div>
@endsection
