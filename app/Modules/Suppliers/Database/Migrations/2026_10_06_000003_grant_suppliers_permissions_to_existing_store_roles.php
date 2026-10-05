<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Les rôles des boutiques existantes ont été créés avant les permissions
 * fournisseurs/achats (StoreRoleProvisioner ne s'exécute qu'à la création
 * d'une boutique). Même mécanisme que `rapports.voir` et `ventes.annuler`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            Permissions::SUPPLIERS_VIEW => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER, StoreRole::CASHIER],
            Permissions::SUPPLIERS_CREATE => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER],
            Permissions::SUPPLIERS_UPDATE => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER],
            Permissions::SUPPLIERS_DELETE => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER],
            Permissions::PURCHASES_VIEW => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER],
            Permissions::PURCHASES_CREATE => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER],
        ];

        foreach ($grants as $name => $roles) {
            $roleIds = DB::table('roles')->whereIn('name', $roles)->pluck('id');
            if ($roleIds->isEmpty()) {
                continue;
            }

            $permission = Permission::findOrCreate($name, 'web');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permission->id,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Indiscernable d'une permission accordée à la création d'une boutique ultérieure.
    }
};
