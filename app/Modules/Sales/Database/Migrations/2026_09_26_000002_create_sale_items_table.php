<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            // Denormalized store_id, same pattern as stock_movements/
            // cash_movements — direct tenant-scoped queries without a
            // join through `sales`, and BelongsToStore needs the column.
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Snapshot at time of sale — a later rename/price change on
            // Product must never alter a past receipt. See docs/sales.md §"Snapshot".
            $table->string('product_name');
            $table->string('pricing_mode');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('quantity', 12, 3);
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();

            $table->index('sale_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
