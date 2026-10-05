<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte client (crédit) — docs/customers.md §12. Ledger append-only, comme
 * mouvements_stock et mouvements_caisse : le solde dû est la somme des
 * montants (positif = le client doit, négatif = avoir en sa faveur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_compte_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // vente_credit (+), paiement (−), annulation_vente (−)
            $table->string('type');
            $table->decimal('montant', 12, 2);
            // Pour un paiement : caisse (entrée en caisse) ou externe.
            $table->string('mode')->nullable();
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->restrictOnDelete();
            $table->nullableMorphs('reference');
            $table->string('note', 500)->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_compte_client');
    }
};
