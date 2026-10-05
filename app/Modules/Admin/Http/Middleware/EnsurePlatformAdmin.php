<?php

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seuls les administrateurs de la plateforme, actifs, accèdent à l'interface
 * d'administration. Un compte retiré ou désactivé en cours de session est
 * déconnecté immédiatement.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isPlatformAdmin() || ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('connexion')->withErrors(['email' => "Ce compte n'a pas accès à l'administration."]);
        }

        return $next($request);
    }
}
