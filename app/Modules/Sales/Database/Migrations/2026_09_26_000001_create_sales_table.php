<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            // Neither register nor session is ever hard-deleted (both
            // modules only deactivate/close) — restrictOnDelete matches
            // the defensive pattern used throughout for history-bearing FKs.
            $table->foreignId('caisse_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_caisse_id')->constrained('sessions_caisse')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendeur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            // Nullable at the DB level only because it's filled in right
            // after the row's own id is known (same transaction) — see
            // docs/sales.md §"Référence".
            $table->string('reference')->nullable();
            $table->decimal('sous_total', 12, 2);
            $table->decimal('montant_remise', 12, 2)->default(0);
            $table->decimal('montant_total', 12, 2);
            $table->string('statut');
            $table->string('mode_paiement');
            $table->string('cle_idempotence')->nullable();
            $table->timestamp('vendue_le');
            $table->timestamps();

            $table->unique(['boutique_id', 'reference']);
            // Nullable + unique: multiple checkouts without a client-
            // generated key are all distinct sales; two with the SAME
            // key in the same store collide — see docs/sales.md §"Idempotence".
            $table->unique(['boutique_id', 'cle_idempotence']);
            $table->index('vendue_le');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventes');
    }
};
