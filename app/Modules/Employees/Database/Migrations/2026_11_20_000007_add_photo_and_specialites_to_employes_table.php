<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fiche employé (coiffeur) : photo et spécialités, facultatives. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('poste');
            $table->string('specialites', 500)->nullable()->after('photo');
        });
    }

    public function down(): void
    {
        Schema::table('employes', fn (Blueprint $table) => $table->dropColumn(['photo', 'specialites']));
    }
};
