<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global (sin tenant_id), igual que articles/mentions: una extraccion
     * es sobre el CONTENIDO del articulo, no de un tenant.
     */
    public function up(): void
    {
        Schema::create('extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->string('modelo');
            $table->json('json_resultado');
            $table->decimal('confianza', 4, 3);
            $table->unsignedInteger('tokens_in');
            $table->unsignedInteger('tokens_out');
            $table->decimal('costo', 8, 4)->nullable();
            $table->timestamps();

            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extractions');
    }
};
