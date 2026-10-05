@extends('admin.layouts.app')
@php
    use App\Modules\Admin\Support\Format;
@endphp

@section('title', 'Utilisateurs')
@section('crumbs', 'Comptes des boutiquiers, de leurs équipes et de l\'administration')

@section('content')
    <div class="card">
        <form class="toolbar" method="GET">
            <label class="search">
                <x-admin.icon name="search" />
                <input type="search" name="recherche" value="{{ request('recherche') }}" placeholder="Nom, e-mail, téléphone…">
            </label>
            <div class="chips">
                @php $filter = request('type') === 'admin' ? 'admin' : request('statut', ''); @endphp
                @foreach (['' => 'Tous', 'actif' => 'Actifs', 'inactif' => 'Désactivés', 'admin' => 'Administrateurs'] as $value => $label)
                    @php $query = $value === 'admin' ? ['type' => 'admin', 'statut' => null] : ['statut' => $value ?: null, 'type' => null]; @endphp
                    <a @class(['chip', 'active' => $filter === $value]) href="{{ request()->fullUrlWithQuery([...$query, 'page' => null]) }}">{{ $label }}</a>
                @endforeach
            </div>
            @foreach (['statut', 'type'] as $keep)
                @if (request($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif
            @endforeach
        </form>

        @if ($users->isEmpty())
            <x-admin.empty icon="people-outline" title="Aucun utilisateur trouvé" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Utilisateur</th><th>Téléphone</th><th>Entreprises</th><th>Boutiques</th><th>Statut</th><th>Inscrit le</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($users as $user)
                        @php [$label, $tone] = Format::userStatus($user->statut); $self = $user->is(auth()->user()); @endphp
                        <tr>
                            <td>
                                <div class="cell-main">
                                    <span class="avatar">{{ Format::initials($user->nom) }}</span>
                                    <span>
                                        <span class="cell-title">{{ $user->nom }}</span>
                                        @if ($user->isPlatformAdmin())<x-admin.badge tone="primary" icon="shield-checkmark" style="margin-left:6px">Admin</x-admin.badge>@endif
                                        <span class="cell-sub" style="display:block">{{ $user->email }}</span>
                                    </span>
                                </div>
                            </td>
                            <td>{{ $user->telephone ?: '—' }}</td>
                            <td>{{ $user->business_memberships_count }}</td>
                            <td>{{ $user->store_memberships_count }}</td>
                            <td><x-admin.badge :tone="$tone">{{ $label }}</x-admin.badge></td>
                            <td class="muted">{{ $user->created_at?->locale('fr')->isoFormat('D MMM YYYY') }}</td>
                            <td class="num">
                                @unless ($self)
                                    <form method="POST" action="{{ route('admin.utilisateurs.statut', $user) }}" data-confirm="{{ $user->isActive() ? 'Désactiver ce compte ? Il sera déconnecté de tous ses appareils.' : 'Réactiver ce compte ?' }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="statut" value="{{ $user->isActive() ? 'inactif' : 'actif' }}">
                                        <button class="btn btn-sm {{ $user->isActive() ? 'btn-ghost' : 'btn-outline' }}" type="submit">
                                            <x-admin.icon :name="$user->isActive() ? 'ban-outline' : 'checkmark-circle-outline'" />{{ $user->isActive() ? 'Désactiver' : 'Réactiver' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="muted">Vous</span>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        {{ $users->links() }}
    </div>
@endsection
