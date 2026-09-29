<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo optionnelle du produit : chemin relatif sur le disque `public`
     * (ex: produits/12/uuid.jpg), jamais une URL complète — l'URL est
     * construite à la lecture (ProductResource) pour suivre l'hôte appelant.
     */
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
