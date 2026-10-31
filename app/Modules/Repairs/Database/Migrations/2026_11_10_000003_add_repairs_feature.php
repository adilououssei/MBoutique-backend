<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Feature `reparations`, EN OPTION pour le domaine Électronique (chaque boutique choisit) ; permissions. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('fonctionnalites')->insertOrIgnore(['slug' => 'reparations', 'nom' => 'Réparations', 'categorie' => 'services', 'description' => 'Appareils déposés : diagnostic, pièces, technicien, suivi, acompte et solde.', 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        $id = DB::table('fonctionnalites')->where('slug', 'reparations')->value('id');
        if ($domain = DB::table('domaines_activite')->where('slug', 'electronique')->value('id')) {
            DB::table('fonctionnalites_domaine')->updateOrInsert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $id], ['active_par_defaut' => false, 'optionnelle' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $staff = [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER, StoreRole::CASHIER, StoreRole::EMPLOYEE];
        foreach ([Permissions::REPAIRS_VIEW, Permissions::REPAIRS_MANAGE] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (DB::table('roles')->whereIn('name', $staff)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('fonctionnalites')->where('slug', 'reparations')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};
