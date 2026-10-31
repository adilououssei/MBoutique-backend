<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prestation au tarif variable : le prix est un « à partir de », ajusté sur le dossier ou le devis. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('prix_variable')->default(false)->after('prix');
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('prix_variable'));
    }
};
