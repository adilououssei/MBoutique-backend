<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion · MBoutique Admin</title>
    <link rel="icon" href="{{ asset('admin-assets/img/favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}?v={{ filemtime(public_path('admin-assets/admin.css')) }}">
</head>
<body>
<div class="auth">
    <section class="auth-side">
        <img class="auth-logo" src="{{ asset('admin-assets/img/logo-fond-noir.png') }}" alt="MBoutique">

        <div class="auth-pitch">
            <h2>Pilotez toute la plateforme <span>MBoutique</span>.</h2>
            <p>Boutiques, ateliers, salons de beauté, restaurants : suivez les entreprises clientes, leurs abonnements et les fonctionnalités de chaque métier.</p>
            <div class="auth-points">
                <div class="auth-point"><span class="icon-circle"><x-admin.icon name="business" /></span>Entreprises et boutiques</div>
                <div class="auth-point"><span class="icon-circle"><x-admin.icon name="pricetags" /></span>Forfaits et abonnements</div>
                <div class="auth-point"><span class="icon-circle"><x-admin.icon name="apps" /></span>Fonctionnalités par métier</div>
            </div>
        </div>

        <div class="auth-foot">© {{ date('Y') }} MBoutique · Accès réservé à l'équipe MBoutique</div>
    </section>

    <main class="auth-main">
        <form class="auth-card" method="POST" action="{{ route('connexion.store') }}" novalidate>
            @csrf
            <img class="mark" src="{{ asset('admin-assets/img/logo-mark.png') }}" alt="">
            <div>
                <h1>Bon retour !</h1>
                <p class="lead">Connectez-vous à l'administration MBoutique.</p>
            </div>

            @error('email')
                <div class="alert alert-danger"><x-admin.icon name="alert-circle" />{{ $message }}</div>
            @enderror
            @if ($errors->has('mot_de_passe'))
                <div class="alert alert-danger"><x-admin.icon name="alert-circle" />{{ $errors->first('mot_de_passe') }}</div>
            @endif

            <div class="field">
                <label for="email">Adresse e-mail</label>
                <div class="input-icon">
                    <x-admin.icon name="mail-outline" />
                    <input class="input @error('email') invalid @enderror" type="email" id="email" name="email" value="{{ old('email') }}" placeholder="vous@mboutique.com" autocomplete="username" required autofocus>
                </div>
            </div>

            <div class="field">
                <label for="mot_de_passe">Mot de passe</label>
                <div class="input-icon password">
                    <x-admin.icon name="lock-closed-outline" />
                    <input class="input" type="password" id="mot_de_passe" name="mot_de_passe" placeholder="••••••••" autocomplete="current-password" required>
                    <button type="button" id="toggle-password" aria-label="Afficher le mot de passe"><x-admin.icon name="eye-outline" /></button>
                </div>
            </div>

            <label class="checkbox"><input type="checkbox" name="se_souvenir" value="1"> Rester connecté</label>

            <button class="btn btn-block" type="submit" style="min-height:50px">Se connecter <x-admin.icon name="arrow-forward" /></button>
        </form>
    </main>
</div>
<script>
    (function () {
        var input = document.getElementById('mot_de_passe');
        var button = document.getElementById('toggle-password');
        var icons = { hidden: @json(\App\Modules\Admin\Support\Icons::glyph('eye-outline')), shown: @json(\App\Modules\Admin\Support\Icons::glyph('eye-off-outline')) };
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.firstElementChild.textContent = show ? icons.shown : icons.hidden;
        });
    })();
</script>
</body>
</html>
