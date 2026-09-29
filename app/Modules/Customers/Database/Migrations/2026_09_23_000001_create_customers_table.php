<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('nom_entreprise')->nullable();
            $table->string('adresse')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            // SoftDeletes, not a hard delete — a Customer may already be
            // referenced by Sale.customer_id once Sales exists; see
            // docs/customers.md §"Suppression".
            $table->softDeletes();

            // No uniqueness on name/phone/email, deliberately — see
            // docs/customers.md §"Duplication". store_id is already
            // indexed by the foreign key itself, no separate index needed.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
