@extends('admin.layouts.app')

@section('title', $feature->nom)
@section('crumbs')
    <a href="{{ route('admin.fonctionnalites.index') }}">Fonctionnalités</a> <x-admin.icon name="chevron-forward" /> {{ $feature->nom }}
@endsection

@section('content')
    @php $current = (array) old('domaines', $domainStates); @endphp
    <form method="POST" action="{{ route('admin.fonctionnalites.update', $feature) }}" class="grid grid-main">
        @csrf @method('PUT')

        <div class="card">
            <div class="card-head"><div><h2>Métiers</h2><div class="sub">Incluse : active pour toutes les boutiques du métier. En option : chaque boutique l'active dans ses Paramètres du module.</div></div></div>
            <div class="card-pad" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
                @foreach ($domains as $domain)
                    <label class="toggle-row">
                        <span class="t-text">
                            <span class="t-title">{{ $domain->nom }}</span>
                            <span class="t-sub" style="display:block">{{ $domain->stores_count }} boutique(s){{ $domain->actif ? '' : ' · masqué à l\'inscription' }}</span>
                        </span>
                        <select class="input" style="width:auto" name="domaines[{{ $domain->id }}]">
                            <option value="" @selected(($current[$domain->id] ?? '') === '')>Non</option>
                            <option value="option" @selected(($current[$domain->id] ?? '') === 'option')>En option</option>
                            <option value="incluse" @selected(($current[$domain->id] ?? '') === 'incluse')>Incluse</option>
                        </select>
                    </label>
                @endforeach
            </div>
            <div class="card-pad muted" style="font-size:12px;line-height:1.6;border-top:1px solid var(--border)">
                @if ($needs->isNotEmpty())Ajouter ce métier active aussi : <strong>{{ $needs->implode(', ') }}</strong>.<br>@endif
                @if ($neededBy->isNotEmpty())Retirer ce métier retire aussi : <strong>{{ $neededBy->implode(', ') }}</strong> (qui en dépendent).<br>@endif
                Le changement est immédiat : les boutiques le voient dès que l'application se rafraîchit.
            </div>
        </div>

        <div class="card card-pad form">
            <x-admin.field name="nom" label="Nom" required>
                <input class="input @error('nom') invalid @enderror" id="nom" name="nom" value="{{ old('nom', $feature->nom) }}">
            </x-admin.field>
            <x-admin.field name="categorie" label="Type d'activité" required>
                <select class="input" id="categorie" name="categorie">
                    @foreach ($categories as $value => $label)
                        <option value="{{ $value }}" @selected(old('categorie', $feature->categorie ?? 'autres') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field name="description" label="Description">
                <textarea class="input" id="description" name="description" rows="3">{{ old('description', $feature->description) }}</textarea>
            </x-admin.field>
            <x-admin.toggle name="actif" :checked="old('actif', $feature->actif)" title="Active sur la plateforme" sub="Désactivée, elle disparaît de toutes les boutiques, quel que soit leur métier." />
            <div class="info-list"><div class="info-row"><span class="k">Identifiant</span><span class="v">{{ $feature->slug }}</span></div></div>
            <button class="btn btn-block" type="submit"><x-admin.icon name="save-outline" />Enregistrer</button>
        </div>
    </form>
@endsection
