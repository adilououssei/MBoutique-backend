<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_vente', function (Blueprint $table) {
            $table->id();
            // Denormalized store_id, same pattern as stock_movements/
            // cash_movements — direct tenant-scoped queries without a
            // join through `sales`, and BelongsToStore needs the column.
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vente_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained()->restrictOnDelete();
            // Snapshot at time of sale — a later rename/price change on
            // Product must never alter a past receipt. See docs/sales.md §"Snapshot".
            $table->string('nom_produit');
            $table->string('mode_prix');
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('quantite', 12, 3);
            $table->decimal('montant_total', 12, 2);
            $table->timestamps();

            $table->index('vente_id');
            $table->index('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_vente');
    }
};
