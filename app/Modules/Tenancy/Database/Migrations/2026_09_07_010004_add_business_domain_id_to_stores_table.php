<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred from the Phase 1 stores migration on purpose (see
 * docs/stores.md) until the Features module — and business_domains — existed.
 * No production data exists yet at this stage of the project, so the
 * column is added as NOT NULL directly rather than nullable-then-backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->foreignId('domaine_activite_id')
                ->after('entreprise_id')
                ->constrained('domaines_activite')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domaine_activite_id');
        });
    }
};
