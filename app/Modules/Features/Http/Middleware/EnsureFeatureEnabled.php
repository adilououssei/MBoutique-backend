<?php

namespace App\Modules\Features\Http\Middleware;

use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware(['auth:sanctum', 'store', 'feature:produits'])` — must run
 * after 'store' (needs the resolved Store) and before any permission
 * check (docs/multi-tenancy.md §5 pipeline): whether the feature exists
 * for this store is a different question from who may use it, and is
 * answered first, see docs/feature-gate.md.
 */
class EnsureFeatureEnabled
{
    public function __construct(private readonly FeatureGate $featureGate) {}

    public function handle(Request $request, Closure $next, string $featureSlug): Response
    {
        $store = $request->route('store');

        abort_unless($store instanceof Store, 500, 'EnsureFeatureEnabled nécessite un paramètre de route {store} résolu.');

        if (! $this->featureGate->allows($store, $featureSlug)) {
            return ApiResponse::error("Cette fonctionnalité n'est pas disponible pour cette boutique.", [], 403, 'FONCTIONNALITE_DESACTIVEE');
        }

        return $next($request);
    }
}
