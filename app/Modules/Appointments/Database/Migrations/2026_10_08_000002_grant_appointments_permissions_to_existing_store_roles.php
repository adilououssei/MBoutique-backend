<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Permissions rendez-vous pour les rôles des boutiques existantes (même mécanisme que les modules précédents). */
return new class extends Migration
{
    public function up(): void
    {
        $managers = [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER];
        $grants = [
            Permissions::APPOINTMENTS_VIEW => [...$managers, StoreRole::CASHIER, StoreRole::EMPLOYEE],
            Permissions::APPOINTMENTS_CREATE => [...$managers, StoreRole::CASHIER],
            Permissions::APPOINTMENTS_UPDATE => [...$managers, StoreRole::CASHIER],
            Permissions::APPOINTMENTS_CANCEL => [...$managers, StoreRole::CASHIER],
        ];

        foreach ($grants as $name => $roles) {
            $roleIds = DB::table('roles')->whereIn('name', $roles)->pluck('id');
            if ($roleIds->isEmpty()) {
                continue;
            }
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
