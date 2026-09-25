<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: a Product is only ever soft-deleted in
            // this codebase, so this never fires in practice — kept for
            // the same defensive reason as Product.category_id.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // DECIMAL(12,3), not float/double — see docs/inventory.md
            // §"Quantités". Never edited directly: every change goes
            // through InventoryService, which also writes the
            // StockMovement that justifies it.
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('minimum_quantity', 12, 3)->nullable();
            $table->timestamps();

            // One current-stock row per product per store — see
            // docs/inventory.md §"Stock vs StockMovement".
            $table->unique(['store_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
