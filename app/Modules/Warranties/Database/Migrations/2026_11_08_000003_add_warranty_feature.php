<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Feature `garanties` (dépend de `ventes`, `produits`), défaut du domaine Électronique ; permissions. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('fonctionnalites')->insertOrIgnore(['slug' => 'garanties', 'nom' => 'Garanties', 'categorie' => 'commerce', 'description' => 'Garanties créées à la vente, recherche par IMEI ou facture, réclamations.', 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        foreach (['ventes', 'produits'] as $dependency) {
            if (isset($ids[$dependency])) {
                DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids['garanties'], 'depend_de_fonctionnalite_id' => $ids[$dependency], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if ($domain = DB::table('domaines_activite')->where('slug', 'electronique')->value('id')) {
            DB::table('fonctionnalites_domaine')->updateOrInsert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids['garanties']], ['active_par_defaut' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $managers = [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER];
        foreach ([Permissions::WARRANTIES_VIEW => [...$managers, StoreRole::CASHIER, StoreRole::EMPLOYEE], Permissions::WARRANTIES_MANAGE => [...$managers, StoreRole::CASHIER]] as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('fonctionnalites')->where('slug', 'garanties')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('dependances_fonctionnalites')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};
