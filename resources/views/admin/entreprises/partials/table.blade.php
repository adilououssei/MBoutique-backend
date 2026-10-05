@php use App\Modules\Admin\Support\Format; @endphp
@if ($businesses->isEmpty())
    <x-admin.empty icon="business-outline" title="Aucune entreprise" message="Les entreprises créées depuis l'application apparaîtront ici." />
@else
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Entreprise</th>
                <th>Propriétaire</th>
                <th>Boutiques</th>
                <th>Forfait</th>
                <th>Statut</th>
                <th>Inscrite le</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($businesses as $business)
                @php
                    [$statusLabel, $statusTone] = Format::businessStatus($business->statut);
                    [$subLabel, $subTone] = Format::subscriptionStatus($business->subscription?->statut);
                @endphp
                <tr>
                    <td>
                        <a class="cell-main" href="{{ route('admin.entreprises.show', $business) }}">
                            <span class="avatar square">{{ Format::initials($business->nom) }}</span>
                            <span>
                                <span class="cell-title">{{ $business->nom }}</span>
                                <span class="cell-sub" style="display:block">{{ $business->pays ?: '—' }} · {{ $business->devise }}</span>
                            </span>
                        </a>
                    </td>
                    <td>
                        <div class="cell-title" style="font-weight:600">{{ $business->owner?->nom ?? '—' }}</div>
                        <div class="cell-sub">{{ $business->owner?->email }}</div>
                    </td>
                    <td><span class="strong">{{ $business->stores_count }}</span></td>
                    <td>
                        <div style="font-weight:600">{{ $business->subscription?->plan?->nom ?? '—' }}</div>
                        <x-admin.badge :tone="$subTone" style="margin-top:4px">{{ $subLabel }}</x-admin.badge>
                    </td>
                    <td><x-admin.badge :tone="$statusTone">{{ $statusLabel }}</x-admin.badge></td>
                    <td class="muted">{{ $business->created_at?->locale('fr')->isoFormat('D MMM YYYY') }}</td>
                    <td class="num"><a class="row-link" href="{{ route('admin.entreprises.show', $business) }}">Ouvrir <x-admin.icon name="chevron-forward" /></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
