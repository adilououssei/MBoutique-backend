@extends('admin.layouts.app')

@section('title', $domain->nom)
@section('crumbs')
    <a href="{{ route('admin.domaines.index') }}">Domaines d'activité</a> <x-admin.icon name="chevron-forward" /> {{ $domain->nom }}
@endsection

@section('content')
    @php $current = (array) old('fonctionnalites', $states); @endphp
    <form method="POST" action="{{ route('admin.domaines.update', $domain) }}" class="grid grid-main" id="domain-form">
        @csrf @method('PUT')

        <div style="display:flex;flex-direction:column;gap:20px">
            @foreach ($groups as $category => $items)
                <div class="card">
                    <div class="card-head">
                        <div>
                            <h2>{{ \App\Modules\Features\Support\FeatureCatalog::categoryOf($category) }}</h2>
                            <div class="sub">
                                @if ($category === 'socle')Utile à presque tous les métiers.
                                @elseif ($category === 'gestion')Outils de gestion, à activer selon le besoin.
                                @else Fonctionnalités pensées pour ce type d'activité ; ajoutez-les à d'autres métiers si besoin.
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="card-pad" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
                        @foreach ($items as $feature)
                            @php
                                $needs = $dependencies->get($feature->id, collect())->map(fn ($id) => $byId->get($id)?->nom)->filter()->implode(', ');
                                $others = $usedBy->get($feature->id, collect());
                            @endphp
                            <label class="toggle-row">
                                <span class="t-text">
                                    <span class="t-title">{{ $feature->nom }}</span>
                                    <span class="t-sub" style="display:block">
                                        @if (! $feature->actif)<strong>Désactivée sur toute la plateforme.</strong> @endif
                                        {{ $feature->description ?: $feature->slug }}
                                    </span>
                                    @if ($needs)<span class="t-sub" style="display:block">Nécessite : {{ $needs }}</span>@endif
                                    <span class="t-sub" style="display:block">{{ $others->isEmpty() ? 'Aucun autre métier' : 'Aussi : '.$others->implode(', ') }}</span>
                                </span>
                                <select class="input" style="width:auto" name="fonctionnalites[{{ $feature->id }}]" data-id="{{ $feature->id }}"
                                    data-needs="{{ $dependencies->get($feature->id, collect())->implode(',') }}"
                                    data-needed-by="{{ implode(',', $dependents->get($feature->id, [])) }}">
                                    <option value="" @selected(($current[$feature->id] ?? '') === '')>Non</option>
                                    <option value="option" @selected(($current[$feature->id] ?? '') === 'option')>En option</option>
                                    <option value="incluse" @selected(($current[$feature->id] ?? '') === 'incluse')>Incluse</option>
                                </select>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
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
                <div class="muted" style="font-size:12px;line-height:1.5">Un retrait est appliqué tout de suite : l'application le reflète dès qu'elle se rafraîchit (retour dans l'application, accueil, menu).</div>
            </div>

            <div class="card">
                <div class="card-head"><div><h2>Textes du métier</h2><div class="sub">Exemples affichés dans les formulaires de l'application (« Ex : … »). Vide = texte neutre.</div></div></div>
                <div class="card-pad form">
                    @foreach ($vocabularyFields as $key => $label)
                        <x-admin.field :name="'vocabulaire.'.$key" :label="$label">
                            @if ($key === 'unite')
                                <select class="input" id="vocabulaire.{{ $key }}" name="vocabulaire[{{ $key }}]">
                                    <option value="">Pièce (par défaut)</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->value }}" @selected(old('vocabulaire.'.$key, $domain->vocabulaire[$key] ?? '') === $unit->value)>{{ $unit->value }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input class="input" id="vocabulaire.{{ $key }}" name="vocabulaire[{{ $key }}]" value="{{ old('vocabulaire.'.$key, $domain->vocabulaire[$key] ?? '') }}">
                            @endif
                        </x-admin.field>
                    @endforeach
                </div>
            </div>
        </div>
    </form>

    <script>
        // Proposer une fonctionnalité (option ou incluse) inclut ce dont elle a besoin ; la retirer retire ce qui en dépend.
        (function () {
            const selects = Array.from(document.querySelectorAll('#domain-form select[data-id]'));
            const byId = Object.fromEntries(selects.map((s) => [s.dataset.id, s]));
            const ids = (s, attr) => (s.dataset[attr] || '').split(',').filter(Boolean);
            function cascade(select) {
                if (select.value !== '') {
                    ids(select, 'needs').forEach((id) => { const o = byId[id]; if (o && o.value === '') { o.value = 'incluse'; cascade(o); } });
                } else {
                    ids(select, 'neededBy').forEach((id) => { const o = byId[id]; if (o && o.value !== '') { o.value = ''; cascade(o); } });
                }
            }
            selects.forEach((s) => s.addEventListener('change', () => cascade(s)));
        })();
    </script>
@endsection
