<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('code')->nullable();
            $table->boolean('actif')->default(true);
            // No FK constraint: cash_register_sessions (created in the
            // next migration) references cash_registers, so a real FK
            // here would be circular. The only writer is
            // CashRegisterService, inside the same transaction that also
            // updates cash_register_sessions.status — see docs/cash-register.md
            // §"Une seule session ouverte". unique(): a session id can
            // only ever be "the open one" for a single register.
            $table->unsignedBigInteger('session_ouverte_id')->nullable()->unique();
            $table->timestamps();

            // Nullable code: MySQL and SQLite both treat NULL as distinct
            // in a unique index, so any number of code-less registers
            // are allowed per store, while two registers that DO have a
            // code must not collide.
            $table->unique(['boutique_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisses');
    }
};
