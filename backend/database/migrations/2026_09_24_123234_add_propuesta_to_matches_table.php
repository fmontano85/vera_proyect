<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo de resolucion en dos pasos (seccion 3.2 del CLAUDE.md raiz:
 * "analista... propone resoluciones, no aprueba" vs "oficial_cumplimiento
 * resuelve coincidencias"). 'estado' sigue siendo el valor FINAL (lo pone
 * unicamente resolver(), solo oficial_cumplimiento/admin); estas columnas
 * nuevas son la sugerencia del analista, que no cambia 'estado' por si
 * sola. El oficial puede resolver igual o distinto a lo propuesto - la
 * discrepancia queda visible en los datos a proposito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->enum('propuesta_estado', ['confirmado', 'falso_positivo', 'homonimo'])
                ->nullable()
                ->after('estado');
            $table->foreignId('propuesta_por')->nullable()->after('propuesta_estado')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('propuesta_en')->nullable()->after('propuesta_por');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('propuesta_por');
            $table->dropColumn(['propuesta_estado', 'propuesta_en']);
        });
    }
};
