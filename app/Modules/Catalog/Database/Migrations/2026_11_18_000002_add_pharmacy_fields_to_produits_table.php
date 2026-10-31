<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacie : DCI, forme, dosage, présentation, laboratoire (facultatifs),
 * produit délivré sur ordonnance, et conditionnement : `unites_par_boite`
 * (stock compté en unités ; une vente « gros » = une boîte = N unités).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('dci')->nullable()->after('nom');
            $table->string('forme')->nullable()->after('dci');
            $table->string('dosage', 100)->nullable()->after('forme');
            $table->string('presentation')->nullable()->after('dosage');
            $table->string('laboratoire')->nullable()->after('presentation');
            $table->boolean('sur_ordonnance')->default(false)->after('laboratoire');
            $table->unsignedInteger('unites_par_boite')->nullable()->after('unite');
        });
    }

    public function down(): void
    {
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn(['dci', 'forme', 'dosage', 'presentation', 'laboratoire', 'sur_ordonnance', 'unites_par_boite']));
    }
};
