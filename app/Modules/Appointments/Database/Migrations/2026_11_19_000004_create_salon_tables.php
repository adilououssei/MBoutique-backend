<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salon de beauté : plusieurs prestations par rendez-vous, acompte,
 * forfaits (séances prépayées), commissions et points de fidélité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forfaits_prestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->decimal('prix', 12, 2);
            $table->unsignedInteger('nombre_seances');
            $table->unsignedInteger('validite_jours')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('services_forfait', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forfait_id')->constrained('forfaits_prestations')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['forfait_id', 'service_id']);
        });

        Schema::create('forfaits_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forfait_id')->constrained('forfaits_prestations');
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $table->decimal('prix', 12, 2);
            $table->unsignedInteger('seances_total');
            $table->unsignedInteger('seances_utilisees')->default(0);
            $table->timestamp('achete_le');
            $table->date('expire_le')->nullable();
            // actif | epuise | annule (l'expiration se lit sur expire_le)
            $table->string('statut')->default('actif');
            $table->timestamps();

            $table->index(['client_id', 'statut']);
        });

        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->decimal('acompte', 12, 2)->default(0)->after('notes');
            $table->foreignId('forfait_client_id')->nullable()->after('acompte')->constrained('forfaits_clients')->nullOnDelete();
            $table->boolean('seance_decomptee')->default(false)->after('forfait_client_id');
            $table->timestamp('commence_le')->nullable()->after('fin_le');
        });

        Schema::create('prestations_rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rendez_vous_id')->constrained('rendez_vous')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services');
            $table->foreignId('employe_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->unsignedInteger('duree_minutes');
            $table->decimal('prix', 12, 2);
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::create('seances_forfait', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forfait_client_id')->constrained('forfaits_clients')->cascadeOnDelete();
            $table->foreignId('rendez_vous_id')->nullable()->constrained('rendez_vous')->nullOnDelete();
            $table->timestamp('utilisee_le');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained('employes');
            $table->foreignId('vente_id')->constrained('ventes');
            $table->foreignId('ligne_vente_id')->nullable()->constrained('lignes_vente')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->decimal('base', 12, 2);
            // pourcentage | montant
            $table->string('mode');
            $table->decimal('valeur', 12, 2);
            $table->decimal('montant', 12, 2);
            // due | payee | annulee
            $table->string('statut')->default('due');
            $table->foreignId('paiement_employe_id')->nullable()->constrained('paiements_employe')->nullOnDelete();
            $table->timestamps();

            $table->index(['employe_id', 'statut']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->integer('points_fidelite')->default(0)->after('preferences');
        });

        Schema::create('mouvements_fidelite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            // gain | utilisation | annulation | ajustement
            $table->string('type');
            $table->integer('points');
            $table->integer('solde_apres');
            $table->decimal('valeur', 12, 2)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });

        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->foreignId('employe_id')->nullable()->after('service_id')->constrained('employes')->nullOnDelete();
            $table->foreignId('forfait_id')->nullable()->after('employe_id')->constrained('forfaits_prestations')->nullOnDelete();
        });

        Schema::table('ventes', function (Blueprint $table) {
            $table->integer('points_utilises')->default(0)->after('montant_remise');
            $table->decimal('remise_fidelite', 12, 2)->default(0)->after('points_utilises');
        });

        // Les rendez-vous existants gardent leur prestation unique comme première ligne.
        DB::statement('INSERT INTO prestations_rendez_vous (boutique_id, rendez_vous_id, service_id, employe_id, duree_minutes, prix, ordre, created_at, updated_at)
            SELECT r.boutique_id, r.id, r.service_id, r.employe_id, '.$this->minutes().', COALESCE(s.prix, 0), 0, r.created_at, r.updated_at
            FROM rendez_vous r JOIN services s ON s.id = r.service_id');
    }

    public function down(): void
    {
        Schema::table('ventes', fn (Blueprint $table) => $table->dropColumn(['points_utilises', 'remise_fidelite']));
        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forfait_id');
            $table->dropConstrainedForeignId('employe_id');
        });
        Schema::dropIfExists('mouvements_fidelite');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('points_fidelite'));
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('seances_forfait');
        Schema::dropIfExists('prestations_rendez_vous');
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forfait_client_id');
            $table->dropColumn(['acompte', 'seance_decomptee', 'commence_le']);
        });
        Schema::dropIfExists('forfaits_clients');
        Schema::dropIfExists('services_forfait');
        Schema::dropIfExists('forfaits_prestations');
    }

    /** Durée en minutes entre debut_le et fin_le, selon le moteur. */
    private function minutes(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => 'CAST((julianday(r.fin_le) - julianday(r.debut_le)) * 1440 AS INTEGER)',
            'pgsql' => 'CAST(EXTRACT(EPOCH FROM (r.fin_le - r.debut_le)) / 60 AS INTEGER)',
            default => 'TIMESTAMPDIFF(MINUTE, r.debut_le, r.fin_le)',
        };
    }
};
