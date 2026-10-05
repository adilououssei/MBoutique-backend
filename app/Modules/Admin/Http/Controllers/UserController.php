<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Comptes utilisateurs (boutiquiers, employés, administrateurs). */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->withCount(['businessMemberships', 'storeMemberships'])
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('nom', 'like', $term)->orWhere('email', 'like', $term)->orWhere('telephone', 'like', $term));
            })
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->input('type') === 'admin', fn ($q) => $q->where('est_admin_plateforme', true))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.utilisateurs.index', ['users' => $users]);
    }

    /**
     * Désactiver un compte bloque la connexion et révoque ses sessions mobiles
     * (jetons Sanctum) : l'effet est immédiat sur tous ses appareils.
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['statut' => ['required', Rule::enum(UserStatus::class)]]);

        if ($user->is($request->user())) {
            return back()->withErrors(['statut' => 'Vous ne pouvez pas désactiver votre propre compte.']);
        }

        $user->forceFill(['statut' => $data['statut']])->save();
        if (! $user->isActive()) {
            $user->tokens()->delete();
        }

        return back()->with('succes', $user->isActive() ? "Le compte de {$user->nom} est réactivé." : "Le compte de {$user->nom} est désactivé et ses appareils sont déconnectés.");
    }
}
