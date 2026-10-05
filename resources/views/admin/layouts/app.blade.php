<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · MBoutique Admin</title>
    <link rel="icon" href="{{ asset('admin-assets/img/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v={{ filemtime(public_path('admin-assets/admin.css')) }}">
</head>
<body>
@php
    $nav = [
        ['route' => 'admin.tableau-de-bord', 'match' => 'admin.tableau-de-bord', 'icon' => 'grid', 'label' => 'Tableau de bord'],
        ['route' => 'admin.entreprises.index', 'match' => 'admin.entreprises.*', 'icon' => 'business', 'label' => 'Entreprises'],
        ['route' => 'admin.boutiques.index', 'match' => 'admin.boutiques.*', 'icon' => 'storefront', 'label' => 'Boutiques'],
        ['route' => 'admin.utilisateurs.index', 'match' => 'admin.utilisateurs.*', 'icon' => 'people', 'label' => 'Utilisateurs'],
    ];
    $config = [
        ['route' => 'admin.forfaits.index', 'match' => 'admin.forfaits.*', 'icon' => 'pricetags', 'label' => 'Forfaits'],
        ['route' => 'admin.domaines.index', 'match' => 'admin.domaines.*', 'icon' => 'apps', 'label' => "Domaines d'activité"],
    ];
    $me = auth()->user();
@endphp
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('admin.tableau-de-bord') }}" class="brand">
            <img src="{{ asset('admin-assets/img/logo-mark-fond-noir.png') }}" alt="">
            <div>
                <div class="brand-name">M <span>Boutique</span></div>
                <div class="brand-sub">Administration</div>
            </div>
        </a>

        <nav class="nav">
            @foreach ($nav as $item)
                <a href="{{ route($item['route']) }}" @class(['active' => request()->routeIs($item['match'])])>
                    <x-admin.icon :name="$item['icon'].(request()->routeIs($item['match']) ? '' : '-outline')" />{{ $item['label'] }}
                </a>
            @endforeach
            <div class="nav-label">Configuration</div>
            @foreach ($config as $item)
                <a href="{{ route($item['route']) }}" @class(['active' => request()->routeIs($item['match'])])>
                    <x-admin.icon :name="$item['icon'].(request()->routeIs($item['match']) ? '' : '-outline')" />{{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="me">
            <span class="avatar" style="background:var(--primary);color:#fff">{{ \App\Modules\Admin\Support\Format::initials($me->nom) }}</span>
            <div class="me-info">
                <div class="me-name">{{ $me->nom }}</div>
                <div class="me-mail">{{ $me->email }}</div>
            </div>
            <form method="POST" action="{{ route('deconnexion') }}">
                @csrf
                <button type="submit" title="Se déconnecter" aria-label="Se déconnecter"><x-admin.icon name="log-out-outline" /></button>
            </form>
        </div>
    </aside>
    <div class="backdrop hidden" id="backdrop"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-row">
                <button class="menu-btn" id="menu-btn" type="button" aria-label="Menu"><x-admin.icon name="menu" /></button>
                <div>
                    <h1>@yield('title')</h1>
                    @hasSection('crumbs')
                        <div class="crumbs">@yield('crumbs')</div>
                    @endif
                </div>
                <div class="topbar-actions">@yield('actions')</div>
            </div>
        </header>

        <main class="content">
            @if (session('succes'))
                <div class="alert alert-success"><x-admin.icon name="checkmark-circle" />{{ session('succes') }}</div>
            @endif
            @if ($errors->any() && ! $errors->hasAny(['email', 'mot_de_passe']))
                <div class="alert alert-danger"><x-admin.icon name="alert-circle" />{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    (function () {
        var sidebar = document.getElementById('sidebar');
        var backdrop = document.getElementById('backdrop');
        function toggle(open) {
            sidebar.classList.toggle('open', open);
            backdrop.classList.toggle('hidden', !open);
        }
        document.getElementById('menu-btn').addEventListener('click', function () { toggle(true); });
        backdrop.addEventListener('click', function () { toggle(false); });

        // Confirmation avant les actions sensibles (suspendre, désactiver…).
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
            });
        });
    })();
</script>
@stack('scripts')
</body>
</html>
