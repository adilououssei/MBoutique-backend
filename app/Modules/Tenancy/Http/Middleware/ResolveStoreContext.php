<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Couche 1 of docs/multi-tenancy.md. Must run after auth:sanctum (so
 * $request->user() is available) and before any Policy/permission check
 * on the route. Resolves {store} from the route, verifies the
 * authenticated user is an ACTIVE member, then populates TenantContext
 * for the rest of the request.
 *
 * Decision made during implementation (was left open in the design docs):
 * a non-member always gets 404, never 403 — whether the store doesn't
 * exist or the user simply isn't (or is no longer) a member is
 * indistinguishable from the outside, so nothing about the store's
 * existence leaks either way.
 */
class ResolveStoreContext
{
    public function __construct(private readonly TenantContextContract $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeStore = $request->route('store');
        $store = $routeStore instanceof Store ? $routeStore : Store::find($routeStore);

        abort_if(! $store, 404);

        $user = $request->user();

        $isActiveMember = StoreUser::where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->where('status', StoreUserStatus::Active->value)
            ->exists();

        abort_if(! $isActiveMember, 404);

        $request->route()->setParameter('store', $store);

        $this->tenantContext->setStoreId($store->id);

        return $next($request);
    }

    /**
     * Defence for persistent workers (Octane, queues): never let a
     * resolved tenant leak into whatever request/job runs next on the
     * same PHP process. See docs/multi-tenancy.md §8.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->tenantContext->clear();
    }
}
