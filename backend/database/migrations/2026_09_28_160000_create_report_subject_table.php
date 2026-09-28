<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas incluidas en cada reporte, como tabla pivote indexada en vez
 * de la columna JSON reports.personas (hallazgo del code-review
 * 2026-09-28): borrar una persona buscaba sus reportes con un escaneo
 * JSON_CONTAINS de toda la tabla, una vez por persona, y no habia nada que
 * el job pudiera verificar contra un borrado concurrente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_subject', function (Blueprint $table) {
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->primary(['report_id', 'subject_id']);
            $table->index('subject_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('personas');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('personas')->nullable()->after('error');
        });

        Schema::dropIfExists('report_subject');
    }
};
