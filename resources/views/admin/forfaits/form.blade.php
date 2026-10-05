@extends('admin.layouts.app')
@php $editing = $plan->exists; @endphp

@section('title', $editing ? "Modifier « {$plan->nom} »" : 'Nouveau forfait')
@section('crumbs')
    <a href="{{ route('admin.forfaits.index') }}">Forfaits</a> <x-admin.icon name="chevron-forward" /> {{ $editing ? $plan->nom : 'Nouveau' }}
@endsection

@section('content')
    <form method="POST" action="{{ $editing ? route('admin.forfaits.update', $plan) : route('admin.forfaits.store') }}" class="grid grid-main">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card">
                <div class="card-head"><h2>Informations</h2></div>
                <div class="card-pad form">
                    <div class="form-row">
                        <x-admin.field name="nom" label="Nom" required>
                            <input class="input @error('nom') invalid @enderror" id="nom" name="nom" value="{{ old('nom', $plan->nom) }}" placeholder="Pro">
                        </x-admin.field>
                        <x-admin.field name="code" label="Code" required help="Identifiant technique, sans espace (ex. pro).">
                            <input class="input @error('code') invalid @enderror" id="code" name="code" value="{{ old('code', $plan->code) }}" placeholder="pro">
                        </x-admin.field>
                    </div>
                    <div class="form-row">
                        <x-admin.field name="prix_mensuel" label="Prix mensuel" required>
                            <div class="input-group"><input class="input" id="prix_mensuel" name="prix_mensuel" inputmode="decimal" value="{{ old('prix_mensuel', $plan->prix_mensuel !== null ? (float) $plan->prix_mensuel : '') }}" placeholder="0"><span class="suffix">FCFA</span></div>
                        </x-admin.field>
                        <x-admin.field name="prix_annuel" label="Prix annuel" help="Optionnel.">
                            <div class="input-group"><input class="input" id="prix_annuel" name="prix_annuel" inputmode="decimal" value="{{ old('prix_annuel', $plan->prix_annuel !== null ? (float) $plan->prix_annuel : '') }}" placeholder="—"><span class="suffix">FCFA</span></div>
                        </x-admin.field>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Quotas</h2><span class="sub">Laisser vide = illimité</span></div>
                <div class="card-pad form">
                    <div class="form-row three">
                        <x-admin.field name="max_boutiques" label="Boutiques">
                            <input class="input" id="max_boutiques" name="max_boutiques" type="number" min="1" value="{{ old('max_boutiques', $plan->max_boutiques) }}" placeholder="Illimité">
                        </x-admin.field>
                        <x-admin.field name="max_utilisateurs_par_boutique" label="Utilisateurs / boutique">
                            <input class="input" id="max_utilisateurs_par_boutique" name="max_utilisateurs_par_boutique" type="number" min="1" value="{{ old('max_utilisateurs_par_boutique', $plan->max_utilisateurs_par_boutique) }}" placeholder="Illimité">
                        </x-admin.field>
                        <x-admin.field name="max_produits_par_boutique" label="Produits / boutique">
                            <input class="input" id="max_produits_par_boutique" name="max_produits_par_boutique" type="number" min="1" value="{{ old('max_produits_par_boutique', $plan->max_produits_par_boutique) }}" placeholder="Illimité">
                        </x-admin.field>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Fonctionnalités incluses</h2><span class="sub">Aucune cochée = toutes les fonctionnalités du métier</span></div>
                <div class="card-pad" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
                    @php $checked = old('fonctionnalites', $selected); @endphp
                    @foreach ($features as $feature)
                        <label class="toggle-row" style="padding:12px 14px">
                            <span class="t-text"><span class="t-title">{{ $feature->nom }}</span><span class="t-sub" style="display:block">{{ $feature->slug }}</span></span>
                            <span class="switch"><input type="checkbox" name="fonctionnalites[]" value="{{ $feature->id }}" @checked(in_array($feature->id, array_map('intval', (array) $checked), true))><span></span></span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:20px">
            <div class="card card-pad form">
                <x-admin.toggle name="actif" :checked="old('actif', $editing ? $plan->actif : true)" title="Forfait actif" sub="Un forfait inactif n'est plus proposé, les abonnements existants sont conservés." />
                <button class="btn btn-block" type="submit"><x-admin.icon name="save-outline" />{{ $editing ? 'Enregistrer' : 'Créer le forfait' }}</button>
                <a class="btn btn-ghost btn-block" href="{{ route('admin.forfaits.index') }}">Annuler</a>
            </div>
        </div>
    </form>
@endsection
