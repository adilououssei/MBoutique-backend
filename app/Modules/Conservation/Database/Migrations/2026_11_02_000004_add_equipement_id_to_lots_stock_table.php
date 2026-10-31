<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Espace de conservation d'un lot (congélateur, chambre froide…) — module Equipment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots_stock', function (Blueprint $table) {
            $table->foreignId('equipement_id')->nullable()->after('conservation')->constrained('equipements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lots_stock', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipement_id');
        });
    }
};
