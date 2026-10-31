<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dépenses d'exploitation (électricité, glace, transport, loyer…) — docs/modules.md
 * §Expenses. Payées depuis une caisse (sortie référencée) ou hors caisse.
 * Append-only : une erreur se corrige par une annulation (l'argent revient en caisse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->string('categorie');
            $table->string('libelle');
            $table->decimal('montant', 12, 2);
            $table->string('mode'); // caisse | externe
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->restrictOnDelete();
            $table->timestamp('depense_le');
            $table->string('note', 1000)->nullable();
            $table->string('statut')->default('validee'); // validee | annulee
            $table->timestamp('annulee_le')->nullable();
            $table->foreignId('annulee_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('motif_annulation', 500)->nullable();
            $table->string('cle_idempotence')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'reference']);
            $table->unique(['boutique_id', 'cle_idempotence']);
            $table->index(['categorie', 'depense_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
