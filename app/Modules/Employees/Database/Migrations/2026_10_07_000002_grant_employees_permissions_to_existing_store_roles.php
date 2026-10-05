<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accorde les permissions employés aux rôles des boutiques existantes —
 * même mécanisme que `rapports.voir`, `ventes.annuler` et les fournisseurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->whereIn('name', [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER])->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        foreach ([Permissions::EMPLOYEES_VIEW, Permissions::EMPLOYEES_MANAGE] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Indiscernable d'une permission accordée à la création d'une boutique ultérieure.
    }
};
