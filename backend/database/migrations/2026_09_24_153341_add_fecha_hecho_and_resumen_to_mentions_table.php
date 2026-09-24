<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura manual (seccion 3.7): fecha_hecho/resumen son parte del
 * formulario de captura manual (ambos opcionales). Para una extraccion
 * automatica esta info ya vive en extractions.json_resultado - aqui
 * quedan null a proposito, no se duplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentions', function (Blueprint $table) {
            $table->date('fecha_hecho')->nullable()->after('rol');
            $table->text('resumen')->nullable()->after('confianza');
        });
    }

    public function down(): void
    {
        Schema::table('mentions', function (Blueprint $table) {
            $table->dropColumn(['fecha_hecho', 'resumen']);
        });
    }
};
