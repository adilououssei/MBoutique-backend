@extends('admin.layouts.app')

@section('title', "Domaines d'activité")
@section('crumbs', 'Les métiers proposés à l\'inscription et leurs fonctionnalités par défaut')

@section('content')
    <div class="grid grid-3">
        @forelse ($domains as $domain)
            <a class="card card-pad" href="{{ route('admin.domaines.show', $domain) }}" style="display:flex;flex-direction:column;gap:14px">
                <div class="flex">
                    <span class="icon-circle tone-primary"><x-admin.icon :name="$domain->icone ?: 'storefront'" /></span>
                    <div style="flex:1;min-width:0">
                        <div class="strong" style="font-size:16px">{{ $domain->nom }}</div>
                        <div class="muted" style="font-size:12px">{{ $domain->stores_count }} boutique(s)</div>
                    </div>
                    <x-admin.badge :tone="$domain->actif ? 'success' : 'neutral'">{{ $domain->actif ? 'Proposé' : 'Masqué' }}</x-admin.badge>
                </div>
                @if ($domain->description)<p class="muted" style="font-size:13px;line-height:1.5">{{ $domain->description }}</p>@endif
                <div>
                    <div class="flex" style="justify-content:space-between;margin-bottom:6px;font-size:12px"><span class="muted">Fonctionnalités actives</span><span class="strong">{{ $domain->fonctionnalites_count }} / {{ $featureCount }}</span></div>
                    <div class="progress"><span style="width: {{ $featureCount ? round($domain->fonctionnalites_count / $featureCount * 100) : 0 }}%"></span></div>
                </div>
            </a>
        @empty
            <div class="card" style="grid-column:1/-1"><x-admin.empty icon="apps-outline" title="Aucun domaine d'activité" message="Lancez le seeder des fonctionnalités (FeatureSeeder)." /></div>
        @endforelse
    </div>
@endsection
