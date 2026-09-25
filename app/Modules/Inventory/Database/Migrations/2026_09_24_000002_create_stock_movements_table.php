<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            // Denormalized alongside stock_id on purpose — lets Sales
            // (later) and reporting query "every movement for this
            // product" without a join, per the Phase 4.1 brief.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type');
            // Signed: positive = entry, negative = exit. For `stocktake`
            // this is the resulting delta (counted - previous), not the
            // counted value itself — see docs/inventory.md §"Stocktake".
            $table->decimal('quantity', 12, 3);
            $table->decimal('quantity_before', 12, 3);
            $table->decimal('quantity_after', 12, 3);
            // Prepared for a future Sale/PurchaseOrder reference —
            // nothing writes it yet in this phase (docs/inventory.md §14).
            $table->nullableMorphs('reference');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Append-only ledger: no unique constraint here beyond the
            // primary key, and deliberately no update/delete endpoint
            // anywhere in this module — see docs/inventory.md §"Immutabilité".
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
