@extends('admin.layouts.app')
@php
    use App\Modules\Admin\Support\Format;
    use App\Modules\Tenancy\Enums\StoreStatus;
@endphp

@section('title', 'Boutiques')
@section('crumbs', 'Tous les points de vente de la plateforme')

@section('content')
    <div class="card">
        <form class="toolbar" method="GET">
            <label class="search">
                <x-admin.icon name="search" />
                <input type="search" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, téléphone, entreprise…">
            </label>
            <div class="chips">
                @foreach (['' => 'Toutes', 'active' => 'Actives', 'inactive' => 'Désactivées'] as $value => $label)
                    <a @class(['chip', 'active' => request('statut', '') === $value]) href="{{ request()->fullUrlWithQuery(['statut' => $value ?: null, 'page' => null]) }}">{{ $label }}</a>
                @endforeach
            </div>
            @if (request('statut'))<input type="hidden" name="statut" value="{{ request('statut') }}">@endif
        </form>

        @if ($stores->isEmpty())
            <x-admin.empty icon="storefront-outline" title="Aucune boutique trouvée" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Boutique</th><th>Entreprise</th><th>Métier</th><th>Membres</th><th>Statut</th><th>Créée le</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($stores as $store)
                        @php [$label, $tone] = Format::storeStatus($store->statut); $active = $store->statut === StoreStatus::Active; @endphp
                        <tr>
                            <td>
                                <div class="cell-main">
                                    <span class="avatar square"><x-admin.icon name="storefront" /></span>
                                    <span><span class="cell-title">{{ $store->nom }}</span><span class="cell-sub" style="display:block">{{ $store->telephone ?: $store->adresse ?: '—' }}</span></span>
                                </div>
                            </td>
                            <td><a class="row-link" href="{{ route('admin.entreprises.show', $store->business) }}">{{ $store->business?->nom }}</a></td>
                            <td>{{ $store->businessDomain?->nom ?? '—' }}</td>
                            <td>{{ $store->store_users_count }}</td>
                            <td><x-admin.badge :tone="$tone">{{ $label }}</x-admin.badge></td>
                            <td class="muted">{{ $store->created_at?->locale('fr')->isoFormat('D MMM YYYY') }}</td>
                            <td class="num">
                                <form method="POST" action="{{ route('admin.boutiques.statut', $store) }}" data-confirm="{{ $active ? 'Désactiver cette boutique ? Elle ne sera plus accessible depuis l\'application.' : 'Réactiver cette boutique ?' }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="statut" value="{{ $active ? 'inactive' : 'active' }}">
                                    <button class="btn btn-sm {{ $active ? 'btn-ghost' : 'btn-outline' }}" type="submit">
                                        <x-admin.icon :name="$active ? 'pause-circle-outline' : 'play-circle-outline'" />{{ $active ? 'Désactiver' : 'Réactiver' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        {{ $stores->links() }}
    </div>
@endsection
