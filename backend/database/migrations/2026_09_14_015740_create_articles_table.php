<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global, deduplicado por url (decision confirmada 2026-09-14, ver
     * CLAUDE.md raiz seccion 3.1/3.3) - no lleva tenant_id. El aislamiento
     * de tenant en el pipeline vive en mentions/matches, no aqui.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('url_hash', 64); // sha256(url): 'url' unico pero muy largo para indexar directo
            $table->string('titulo')->nullable();
            $table->string('medio')->nullable();
            $table->timestamp('fecha_publicacion')->nullable();
            $table->string('hash_contenido', 64)->nullable();
            $table->string('evidence_path')->nullable();
            $table->enum('estado_extraccion', ['pendiente', 'completado', 'fallido'])->default('pendiente');
            $table->timestamps();

            $table->unique('url_hash');
            $table->index('fecha_publicacion');
            $table->index('estado_extraccion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
