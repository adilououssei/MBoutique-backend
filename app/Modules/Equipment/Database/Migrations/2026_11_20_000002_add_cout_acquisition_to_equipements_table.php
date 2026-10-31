<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Coût d'achat d'un équipement (facultatif). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipements', function (Blueprint $table) {
            $table->decimal('cout_acquisition', 12, 2)->nullable()->after('date_acquisition');
        });
    }

    public function down(): void
    {
        Schema::table('equipements', fn (Blueprint $table) => $table->dropColumn('cout_acquisition'));
    }
};
