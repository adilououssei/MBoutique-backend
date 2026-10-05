<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personnel de la boutique — docs/modules.md §Employees. Un employé est
 * distinct d'un compte (StoreUser) : il peut ne jamais se connecter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            // Lien facultatif vers le compte d'un membre de la boutique.
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('nom');
            $table->string('poste')->nullable();
            $table->string('telephone')->nullable();
            $table->string('adresse')->nullable();
            $table->date('date_embauche')->nullable();
            $table->decimal('salaire', 12, 2)->nullable();
            $table->string('periodicite_salaire')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            // Soft delete : l'historique des paiements reste consultable.
            $table->softDeletes();
        });

        Schema::create('paiements_employe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained('employes')->restrictOnDelete();
            // salaire, avance, prime
            $table->string('type');
            $table->decimal('montant', 12, 2);
            // Période concernée, libre : « Octobre 2026 », « Semaine 41 »…
            $table->string('periode', 50)->nullable();
            // caisse (sortie d'une session ouverte) ou externe (hors caisse).
            $table->string('mode');
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('paye_le');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['employe_id', 'paye_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_employe');
        Schema::dropIfExists('employes');
    }
};
