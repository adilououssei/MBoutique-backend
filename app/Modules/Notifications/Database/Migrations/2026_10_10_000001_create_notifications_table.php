<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table standard du canal `database` de Laravel (nom et colonnes imposés par
 * le framework, d'où l'exception au nommage français) : `$user->notifications`
 * fonctionne tel quel, et d'autres canaux (push, SMS) pourront s'ajouter sans
 * migration. La boutique concernée est dans `data->boutique_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
