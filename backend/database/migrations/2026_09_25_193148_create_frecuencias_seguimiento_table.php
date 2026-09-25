<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seccion 3.8: dias de seguimiento por nivel de riesgo, configurados por
 * tenant (tabla propia auditada - decision del usuario 2026-09-25).
 * 'sin_nivel' cubre subjects con nivel_riesgo null. Sembrado al crear el
 * tenant (Listeners\SembrarFrecuenciasSeguimientoPorDefecto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frecuencias_seguimiento', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->enum('nivel_riesgo', ['alto', 'medio', 'bajo', 'sin_nivel']);
            $table->unsignedSmallInteger('dias');
            $table->timestamps();

            $table->unique(['tenant_id', 'nivel_riesgo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frecuencias_seguimiento');
    }
};
