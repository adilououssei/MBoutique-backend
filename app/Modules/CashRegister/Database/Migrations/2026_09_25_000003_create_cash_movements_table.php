<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_caisse_id')->constrained('sessions_caisse')->cascadeOnDelete();
            $table->string('type');
            // Signed, DECIMAL(12,2), never float — see docs/cash-register.md §"Argent et précision".
            $table->decimal('montant', 12, 2);
            $table->decimal('solde_avant', 12, 2);
            $table->decimal('solde_apres', 12, 2);
            $table->string('motif')->nullable();
            // Prepared for a future Sale reference — nothing writes it
            // yet in this phase, same pattern as stock_movements.
            $table->nullableMorphs('reference');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            // Append-only ledger: no update/delete endpoint anywhere in
            // this module — see docs/cash-register.md §"Immutabilité".
            $table->index(['session_caisse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_caisse');
    }
};
