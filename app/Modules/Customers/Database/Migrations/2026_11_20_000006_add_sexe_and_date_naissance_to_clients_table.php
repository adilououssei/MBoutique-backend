<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fiche client (coiffure, salon) : sexe et date de naissance, facultatifs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('sexe', 10)->nullable()->after('nom');
            $table->date('date_naissance')->nullable()->after('sexe');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['sexe', 'date_naissance']));
    }
};
