<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: a register with session history is never
            // hard-deleted (docs/cash-register.md §"Caisse inactive"),
            // deactivated via is_active instead.
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            // Preserve the audit trail even if the acting user's account
            // is later removed — same reasoning as StockMovement.created_by_user_id.
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_amount', 12, 2);
            // Computed by the backend at close time from the ledger,
            // never accepted from the client — see docs/cash-register.md §"Expected closing amount".
            $table->decimal('expected_closing_amount', 12, 2)->nullable();
            $table->decimal('actual_closing_amount', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->string('status');
            $table->text('closing_note')->nullable();
            $table->timestamps();

            $table->index(['cash_register_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
