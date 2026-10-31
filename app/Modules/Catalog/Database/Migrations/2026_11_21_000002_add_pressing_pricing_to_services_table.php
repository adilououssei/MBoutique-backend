<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pressing : unité de tarification (pièce, kg, m², paire, lot), délai
 * estimé, consignes, et grille de prix par type d'article
 * (lavage : chemise 500, costume 2 000…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('unite_tarif', 10)->nullable()->after('prix');
            $table->unsignedInteger('delai_heures')->nullable()->after('duree_minutes');
            $table->text('consignes')->nullable()->after('description');
        });

        Schema::create('tarifs_articles_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('type_article', 100);
            $table->decimal('prix', 12, 2);
            $table->timestamps();

            $table->unique(['service_id', 'type_article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifs_articles_service');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['unite_tarif', 'delai_heures', 'consignes']));
    }
};
