<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes de auditoria exportables (seccion 1, punto 5; tabla 'reports'
 * de la seccion 3.3). 'personas' guarda los ids de las personas que el
 * reporte incluye: al borrar una persona (seccion 3.9, punto 5) se borran
 * los reportes que la contienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->enum('tipo', ['ficha_persona', 'actividad_periodo', 'lista_por_riesgo']);
            $table->enum('formato', ['pdf', 'csv']);
            $table->json('parametros');
            $table->enum('estado', ['pendiente', 'generando', 'listo', 'fallido'])->default('pendiente');
            $table->string('archivo_path')->nullable();
            $table->string('error')->nullable();
            $table->json('personas')->nullable();
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generado_en')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
