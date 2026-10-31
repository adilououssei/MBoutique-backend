<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lots de stock (module Conservation) : chaque entrée en stock d'une boutique
 * qui suit la conservation crée un lot daté ; les sorties consomment les lots
 * de la date limite la plus proche d'abord. Une projection du journal des
 * mouvements, jamais la source de vérité des quantités (table `stocks`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->foreignId('mouvement_entree_id')->nullable()->constrained('mouvements_stock')->nullOnDelete();
            $table->string('numero')->nullable();
            $table->decimal('quantite_initiale', 12, 3);
            $table->decimal('quantite_restante', 12, 3);
            $table->timestamp('recu_le');
            $table->date('date_limite')->nullable();
            $table->string('conservation')->nullable(); // frais | congele | transforme
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['produit_id', 'quantite_restante']);
            $table->index('date_limite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots_stock');
    }
};
