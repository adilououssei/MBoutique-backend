<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Connexion de l'administration (page d'accueil du site). */
class LoginController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.connexion');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'mot_de_passe' => ['required', 'string'],
        ], [], ['mot_de_passe' => 'mot de passe']);

        $user = User::query()->where('email', $credentials['email'])->first();

        // Même message pour « mauvais mot de passe » et « pas administrateur » :
        // l'existence d'un compte boutiquier ne doit pas se deviner d'ici.
        if ($user === null || ! Hash::check($credentials['mot_de_passe'], $user->password) || ! $user->isPlatformAdmin()) {
            throw ValidationException::withMessages(['email' => 'Identifiants incorrects ou compte sans accès à l\'administration.']);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'Ce compte est désactivé.']);
        }

        Auth::guard('web')->login($user, $request->boolean('se_souvenir'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.tableau-de-bord'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('connexion');
    }
}
