<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seccion 3.8 del CLAUDE.md raiz (Fase 2, agenda de seguimiento).
 * frecuencia_seguimiento_dias null = usa el default del tenant para su
 * nivel de riesgo. Los subjects existentes quedan con
 * proximo_seguimiento_en null hasta correr vera:inicializar-seguimientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('frecuencia_seguimiento_dias')->nullable();
            $table->date('proximo_seguimiento_en')->nullable();
            $table->timestamp('ultimo_seguimiento_en')->nullable();
            $table->foreignId('ultimo_seguimiento_por')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'proximo_seguimiento_en']);
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'proximo_seguimiento_en']);
            $table->dropConstrainedForeignId('ultimo_seguimiento_por');
            $table->dropColumn(['frecuencia_seguimiento_dias', 'proximo_seguimiento_en', 'ultimo_seguimiento_en']);
        });
    }
};
