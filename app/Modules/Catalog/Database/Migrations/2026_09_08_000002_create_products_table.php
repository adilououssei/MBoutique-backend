<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: a category with products should be
            // deactivated, not deleted out from under them — see
            // docs/database.md §0 typing/delete conventions.
            $table->foreignId('categorie_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('nom');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('code_barres')->nullable();
            $table->string('unite')->default('piece');
            $table->decimal('prix_achat', 12, 2)->nullable();
            // Détail/Gros: two independent price slots, each guarded by
            // its own *_enabled flag. Enforced at the FormRequest layer
            // (CreateProductRequest/UpdateProductRequest) — see docs/catalog.md.
            $table->boolean('vente_detail_active')->default(false);
            $table->decimal('prix_detail', 12, 2)->nullable();
            $table->boolean('vente_gros_active')->default(false);
            $table->decimal('prix_gros', 12, 2)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['boutique_id', 'slug']);
            $table->unique(['boutique_id', 'sku']);
            $table->unique(['boutique_id', 'code_barres']);
            $table->index('categorie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
