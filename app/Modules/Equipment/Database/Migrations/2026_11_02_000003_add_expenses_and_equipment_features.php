<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Features `depenses` et `equipements` (sans dépendance), défaut du seul
 * domaine Boucherie ; permissions accordées aux rôles des boutiques existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['depenses' => 'Dépenses', 'equipements' => 'Équipements'] as $slug => $name) {
            DB::table('fonctionnalites')->insertOrIgnore(['slug' => $slug, 'nom' => $name, 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $butcher = DB::table('domaines_activite')->where('slug', 'boucherie')->value('id');
        if ($butcher !== null) {
            foreach (DB::table('fonctionnalites')->whereIn('slug', ['depenses', 'equipements'])->pluck('id') as $featureId) {
                DB::table('fonctionnalites_domaine')->updateOrInsert(
                    ['domaine_activite_id' => $butcher, 'fonctionnalite_id' => $featureId],
                    ['active_par_defaut' => true, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        $managers = [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER];
        $grants = [
            Permissions::EXPENSES_VIEW => [...$managers, StoreRole::CASHIER],
            Permissions::EXPENSES_CREATE => [...$managers, StoreRole::CASHIER],
            Permissions::EXPENSES_CANCEL => $managers,
            Permissions::EQUIPMENT_VIEW => [...$managers, StoreRole::CASHIER, StoreRole::EMPLOYEE],
            Permissions::EQUIPMENT_MANAGE => $managers,
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
        $ids = DB::table('fonctionnalites')->whereIn('slug', ['depenses', 'equipements'])->pluck('id');
        DB::table('fonctionnalites_domaine')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('fonctionnalites_boutique')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('fonctionnalites')->whereIn('id', $ids)->delete();
    }
};
