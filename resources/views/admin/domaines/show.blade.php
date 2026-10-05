@extends('admin.layouts.app')
@php $byId = $features->keyBy('id'); @endphp

@section('title', $domain->nom)
@section('crumbs')
    <a href="{{ route('admin.domaines.index') }}">Domaines d'activité</a> <x-admin.icon name="chevron-forward" /> {{ $domain->nom }}
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.domaines.update', $domain) }}" class="grid grid-main">
        @csrf @method('PUT')

        <div class="card">
            <div class="card-head">
                <div><h2>Fonctionnalités par défaut</h2><div class="sub">Activées pour toute nouvelle boutique de ce métier. Chaque boutique peut ensuite les ajuster.</div></div>
            </div>
            <div class="card-pad" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
                @php $checked = array_map('intval', (array) old('fonctionnalites', $enabled)); @endphp
                @foreach ($features as $feature)
                    @php
                        $deps = $feature->dependencies->map(fn ($d) => $byId->get($d->depend_de_fonctionnalite_id)?->nom)->filter()->implode(', ');
                    @endphp
                    <label class="toggle-row">
                        <span class="t-text">
                            <span class="t-title">{{ $feature->nom }}</span>
                            <span class="t-sub" style="display:block">
                                @if (! $feature->actif)Désactivée sur toute la plateforme · @endif
                                {{ $deps ? 'Nécessite : '.$deps : ($feature->description ?: $feature->slug) }}
                            </span>
                        </span>
                        <span class="switch"><input type="checkbox" name="fonctionnalites[]" value="{{ $feature->id }}" @checked(in_array($feature->id, $checked, true))><span></span></span>
                    </label>
                @endforeach
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card card-pad form">
                <x-admin.field name="nom" label="Nom" required>
                    <input class="input @error('nom') invalid @enderror" id="nom" name="nom" value="{{ old('nom', $domain->nom) }}">
                </x-admin.field>
                <x-admin.field name="description" label="Description">
                    <textarea class="input" id="description" name="description" rows="3">{{ old('description', $domain->description) }}</textarea>
                </x-admin.field>
                <x-admin.toggle name="actif" :checked="old('actif', $domain->actif)" title="Proposé à l'inscription" sub="Masquer un métier n'affecte pas les boutiques qui l'utilisent déjà." />
                <div class="info-list">
                    <div class="info-row"><span class="k">Boutiques de ce métier</span><span class="v">{{ $domain->stores_count }}</span></div>
                    <div class="info-row"><span class="k">Identifiant</span><span class="v">{{ $domain->slug }}</span></div>
                </div>
                <button class="btn btn-block" type="submit"><x-admin.icon name="save-outline" />Enregistrer</button>
            </div>
        </div>
    </form>
@endsection
