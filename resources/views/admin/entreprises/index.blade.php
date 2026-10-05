@extends('admin.layouts.app')

@section('title', 'Entreprises')
@section('crumbs', 'Les clients de la plateforme et leurs abonnements')

@section('content')
    <div class="card">
        <form class="toolbar" method="GET">
            <label class="search">
                <x-admin.icon name="search" />
                <input type="search" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, raison sociale, e-mail du propriétaire…">
            </label>
            <div class="chips">
                @foreach (['' => 'Toutes', 'active' => 'Actives', 'suspendue' => 'Suspendues'] as $value => $label)
                    <a @class(['chip', 'active' => request('statut', '') === $value]) href="{{ request()->fullUrlWithQuery(['statut' => $value ?: null, 'page' => null]) }}">{{ $label }}</a>
                @endforeach
            </div>
            @if (request('statut'))<input type="hidden" name="statut" value="{{ request('statut') }}">@endif
        </form>
        @include('admin.entreprises.partials.table', ['businesses' => $businesses])
        {{ $businesses->links() }}
    </div>
@endsection
