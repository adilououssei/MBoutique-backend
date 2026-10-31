<?php

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Authorization\Support\StoreRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ordonnances (pharmacie) : prescripteur, médicaments prescrits et
 * délivrances (partielles possibles) rattachées aux ventes. Données de
 * santé : accès par `ordonnances.voir` / `ordonnances.gerer` seulement.
 * Aucune substitution : une délivrance porte sur le produit prescrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordonnances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_patient');
            $table->string('prescripteur');
            $table->string('etablissement')->nullable();
            $table->date('prescrite_le');
            $table->string('photo')->nullable();
            $table->text('note')->nullable();
            // en_attente, partielle, delivree, annulee
            $table->string('statut')->default('en_attente');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'numero']);
            $table->index(['boutique_id', 'statut']);
        });

        Schema::create('lignes_ordonnance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ordonnance_id')->constrained('ordonnances')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('libelle');
            $table->string('posologie', 500)->nullable();
            $table->decimal('quantite_prescrite', 12, 3);
            $table->decimal('quantite_delivree', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('delivrances_ordonnance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ordonnance_id')->constrained('ordonnances')->cascadeOnDelete();
            $table->foreignId('ligne_ordonnance_id')->constrained('lignes_ordonnance')->cascadeOnDelete();
            $table->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $table->foreignId('ligne_vente_id')->constrained('lignes_vente')->cascadeOnDelete();
            $table->decimal('quantite', 12, 3);
            $table->boolean('annulee')->default(false);
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });

        $grants = [
            Permissions::PRESCRIPTIONS_VIEW => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER, StoreRole::CASHIER],
            Permissions::PRESCRIPTIONS_MANAGE => [StoreRole::OWNER, StoreRole::ADMIN, StoreRole::MANAGER],
        ];
        foreach ($grants as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission->id, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('delivrances_ordonnance');
        Schema::dropIfExists('lignes_ordonnance');
        Schema::dropIfExists('ordonnances');
    }
};
