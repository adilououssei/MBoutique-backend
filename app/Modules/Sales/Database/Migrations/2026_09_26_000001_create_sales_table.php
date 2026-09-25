<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Neither register nor session is ever hard-deleted (both
            // modules only deactivate/close) — restrictOnDelete matches
            // the defensive pattern used throughout for history-bearing FKs.
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sold_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Nullable at the DB level only because it's filled in right
            // after the row's own id is known (same transaction) — see
            // docs/sales.md §"Référence".
            $table->string('reference')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('status');
            $table->string('payment_method');
            $table->string('idempotency_key')->nullable();
            $table->timestamp('sold_at');
            $table->timestamps();

            $table->unique(['store_id', 'reference']);
            // Nullable + unique: multiple checkouts without a client-
            // generated key are all distinct sales; two with the SAME
            // key in the same store collide — see docs/sales.md §"Idempotence".
            $table->unique(['store_id', 'idempotency_key']);
            $table->index('sold_at');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
