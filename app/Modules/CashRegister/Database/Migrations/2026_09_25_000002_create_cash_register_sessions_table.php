<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: a register with session history is never
            // hard-deleted (docs/cash-register.md §"Caisse inactive"),
            // deactivated via is_active instead.
            $table->foreignId('caisse_id')->constrained()->restrictOnDelete();
            // Preserve the audit trail even if the acting user's account
            // is later removed — same reasoning as StockMovement.created_by_user_id.
            $table->foreignId('ouverte_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->foreignId('fermee_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('ouverte_le');
            $table->timestamp('fermee_le')->nullable();
            $table->decimal('montant_ouverture', 12, 2);
            // Computed by the backend at close time from the ledger,
            // never accepted from the client — see docs/cash-register.md §"Expected closing amount".
            $table->decimal('montant_fermeture_attendu', 12, 2)->nullable();
            $table->decimal('montant_fermeture_reel', 12, 2)->nullable();
            $table->decimal('ecart', 12, 2)->nullable();
            $table->string('statut');
            $table->text('note_fermeture')->nullable();
            $table->timestamps();

            $table->index(['caisse_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_caisse');
    }
};
