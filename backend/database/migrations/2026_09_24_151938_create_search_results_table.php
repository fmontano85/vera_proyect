<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz, definido 2026-09-24):
 * un registro por CADA resultado que devuelve Brave, antes de que nadie
 * decida procesarlo. RunSubjectSearchJob ya no encola FetchArticleJob
 * directo - crea estas filas en 'nuevo' y se detiene ahi.
 *
 * unique(subject_id, url_hash): si la misma URL vuelve a salir en una
 * busqueda posterior (otro dia) para el mismo subject, no se duplica la
 * tarjeta - se reusa la fila existente (firstOrCreate en el job), sin
 * pisar su estado si el analista ya la proceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_results', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignId('search_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();

            $table->string('url', 2048);
            $table->string('url_hash', 64);
            $table->string('titulo')->nullable();
            $table->text('snippet')->nullable();
            $table->string('medio')->nullable();
            $table->timestamp('fecha_brave')->nullable();

            $table->enum('estado', ['nuevo', 'procesando', 'extraido', 'sin_menciones', 'gap', 'descartado'])
                ->default('nuevo');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->enum('gap_motivo', ['http_403', 'http_error', 'timeout', 'sin_contenido', 'fuera_de_ventana', 'no_html'])
                ->nullable();

            // articles es global (seccion 3.1/3.3) - restrictOnDelete, no
            // se borra un Article por borrar un search_result.
            $table->foreignId('article_id')->nullable()->constrained()->restrictOnDelete();
            // Evidencia de captura manual (seccion 3.7) - disco propio,
            // ver config/vera.php 'evidencia_manual_disk' (independiente
            // de FILESYSTEM_DISK a peticion del usuario).
            $table->string('evidencia_manual_path')->nullable();

            $table->foreignId('descartado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('descartado_en')->nullable();

            $table->timestamps();

            $table->unique(['subject_id', 'url_hash']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_results');
    }
};
