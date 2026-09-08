<?php

namespace App\Shared\Tenancy;

use App\Shared\Tenancy\Contracts\TenantContextContract;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Tells spatie/laravel-permission which "team" (store) the current
 * request is scoped to, so a user's roles/permissions are always
 * evaluated against the store they are currently acting in rather than
 * globally. See config/permission.php ('teams' => true) and
 * docs/permissions.md.
 *
 * No constructor dependencies, by necessity, not by choice:
 * Spatie\Permission\PermissionRegistrar instantiates the configured
 * `team_resolver` with a bare `new $class` (no container), so anything
 * requiring constructor injection would fatal the moment the registrar
 * boots. TenantContextContract is resolved lazily via the `app()` helper
 * instead. Found and fixed during Phase 1 implementation — see
 * docs/audit-2026-09.md addendum in docs/permissions.md §5 bis.
 */
class StoreTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        return app(TenantContextContract::class)->getStoreId();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        if ($id === null) {
            app(TenantContextContract::class)->clear();

            return;
        }

        app(TenantContextContract::class)->setStoreId($id);
    }
}
