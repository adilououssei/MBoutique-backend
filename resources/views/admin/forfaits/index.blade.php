@extends('admin.layouts.app')
@php use App\Modules\Admin\Support\Format; @endphp

@section('title', 'Forfaits')
@section('crumbs', 'Grille tarifaire et quotas des abonnements')
@section('actions')
    <a class="btn" href="{{ route('admin.forfaits.create') }}"><x-admin.icon name="add" />Nouveau forfait</a>
@endsection

@section('content')
    @if ($plans->isEmpty())
        <div class="card">
            <x-admin.empty icon="pricetags-outline" title="Aucun forfait" message="Créez votre grille (Gratuit, Basique, Pro…) pour attribuer un abonnement aux entreprises.">
                <a class="btn" href="{{ route('admin.forfaits.create') }}" style="margin-top:16px"><x-admin.icon name="add" />Créer un forfait</a>
            </x-admin.empty>
        </div>
    @else
        <div class="grid grid-3">
            @foreach ($plans as $plan)
                <div class="card" style="display:flex;flex-direction:column">
                    <div class="card-pad" style="display:flex;flex-direction:column;gap:14px;flex:1">
                        <div class="flex">
                            <span class="icon-circle tone-primary"><x-admin.icon name="pricetag" /></span>
                            <div style="flex:1">
                                <div class="strong" style="font-size:17px">{{ $plan->nom }}</div>
                                <div class="muted" style="font-size:12px">Code : {{ $plan->code }}</div>
                            </div>
                            <x-admin.badge :tone="$plan->actif ? 'success' : 'neutral'">{{ $plan->actif ? 'Actif' : 'Inactif' }}</x-admin.badge>
                        </div>
                        <div>
                            <span style="font-size:28px;font-weight:800;letter-spacing:-.5px">{{ Format::money($plan->prix_mensuel) }}</span><span class="muted"> / mois</span>
                            @if ($plan->prix_annuel !== null)<div class="muted" style="font-size:12px">{{ Format::money($plan->prix_annuel) }} / an</div>@endif
                        </div>
                        <div class="info-list">
                            <div class="info-row"><span class="k">Boutiques</span><span class="v">{{ $plan->max_boutiques ?? 'Illimité' }}</span></div>
                            <div class="info-row"><span class="k">Utilisateurs / boutique</span><span class="v">{{ $plan->max_utilisateurs_par_boutique ?? 'Illimité' }}</span></div>
                            <div class="info-row"><span class="k">Produits / boutique</span><span class="v">{{ $plan->max_produits_par_boutique ?? 'Illimité' }}</span></div>
                            <div class="info-row"><span class="k">Fonctionnalités</span><span class="v">{{ $plan->features_count ?: 'Toutes' }}</span></div>
                        </div>
                    </div>
                    <div class="flex" style="padding:14px 20px;border-top:1px solid var(--border)">
                        <span class="muted"><x-admin.icon name="business-outline" /> {{ $plan->subscriptions_count }} entreprise(s)</span>
                        <span class="spacer"></span>
                        <a class="btn btn-outline btn-sm" href="{{ route('admin.forfaits.edit', $plan) }}"><x-admin.icon name="create-outline" />Modifier</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
