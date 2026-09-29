<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pure existence pivot (a row means "this plan includes this feature") —
 * no extra pivot data, so no dedicated Eloquent model, unlike
 * domain_features which carries is_default_enabled. See docs/subscriptions.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fonctionnalites_forfait', function (Blueprint $table) {
            $table->foreignId('forfait_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fonctionnalite_id')->constrained()->cascadeOnDelete();

            $table->primary(['forfait_id', 'fonctionnalite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fonctionnalites_forfait');
    }
};
