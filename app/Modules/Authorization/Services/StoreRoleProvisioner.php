<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Support\StoreRole;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the template spatie roles for a newly created Store. Roles are
 * team-scoped (config('permission.teams') === true), so they cannot be
 * seeded once globally — a fresh set of Role rows, tied to the new
 * store's team_id, must be created every time a Store is created.
 * See docs/permissions.md §5 bis point 2.
 *
 * Uses findOrCreate() throughout so this never depends on a seeder having
 * run first — permissions and roles are created on demand, idempotently.
 */
class StoreRoleProvisioner
{
    public function __construct(
        private readonly TenantContextContract $tenantContext,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    public function provisionFor(Store $store): void
    {
        $previousStoreId = $this->tenantContext->getStoreId();

        // Role/Permission assignment reads the "current team" from spatie's
        // own registrar (StoreTeamResolver relays TenantContext to it), so
        // it must point at the store we're provisioning for, not whatever
        // store (if any) the current request happens to be scoped to.
        $this->tenantContext->setStoreId($store->id);
        $this->permissionRegistrar->forgetCachedPermissions();

        try {
            foreach (StoreRole::defaultPermissions() as $roleName => $permissionNames) {
                $role = Role::findOrCreate($roleName, 'web');

                $role->syncPermissions(array_map(
                    fn (string $name) => Permission::findOrCreate($name, 'web'),
                    $permissionNames,
                ));
            }
        } finally {
            if ($previousStoreId !== null) {
                $this->tenantContext->setStoreId($previousStoreId);
            } else {
                $this->tenantContext->clear();
            }
            $this->permissionRegistrar->forgetCachedPermissions();
        }
    }
}
