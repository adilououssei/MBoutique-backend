<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Les rôles des boutiques existantes ont été créés avant `ventes.annuler` :
 * StoreRoleProvisioner ne s'exécute qu'à la création d'une boutique. Même
 * mécanisme que la migration de `rapports.voir` (module Reports).
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

        $permission = Permission::findOrCreate(Permissions::SALES_CANCEL, 'web');

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
        // Indiscernable d'une permission accordée à la création d'une
        // boutique ultérieure — rien de sûr à annuler.
    }
};
