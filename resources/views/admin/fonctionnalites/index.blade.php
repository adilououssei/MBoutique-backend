@extends('admin.layouts.app')

@section('title', 'Fonctionnalités')
@section('crumbs', 'Ce que chaque métier peut utiliser, classé par type d\'activité')

@section('content')
    <div style="display:flex;flex-direction:column;gap:20px">
        @foreach ($groups as $category => $features)
            <div class="card">
                <div class="card-head"><h2>{{ \App\Modules\Features\Support\FeatureCatalog::categoryOf($category) }}</h2></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Fonctionnalité</th><th>Métiers qui l'utilisent</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($features as $feature)
                                @php $domains = $domainsByFeature->get($feature->id, collect()); @endphp
                                <tr>
                                    <td>
                                        <div class="strong">{{ $feature->nom }}</div>
                                        <div class="muted" style="font-size:12px">{{ $feature->description ?: $feature->slug }}</div>
                                        @if (! $feature->actif)<x-admin.badge tone="neutral">Désactivée partout</x-admin.badge>@endif
                                    </td>
                                    <td>
                                        @if ($domains->isEmpty())
                                            <span class="muted">Aucun</span>
                                        @else
                                            {{ $domains->implode(', ') }}
                                            <div class="muted" style="font-size:12px">{{ $domains->count() }} / {{ $domainCount }}</div>
                                        @endif
                                    </td>
                                    <td style="text-align:right"><a class="btn btn-sm btn-ghost" href="{{ route('admin.fonctionnalites.edit', $feature) }}">Gérer</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endsection
