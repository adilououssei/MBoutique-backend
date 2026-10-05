<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stores created before the Reports module had their template roles
 * provisioned without `rapports.voir` (StoreRoleProvisioner only runs at
 * store creation). Additive only: grants the permission to the template
 * roles that receive it by default, never touches any other permission a
 * store may have customised. role_has_permissions is not team-scoped, so
 * no TenantContext is needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER])
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $permission = Permission::findOrCreate(Permissions::REPORTS_VIEW, 'web');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permission->id,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions granted here are indistinguishable from ones granted
        // at store creation afterwards — nothing safe to roll back.
    }
};
