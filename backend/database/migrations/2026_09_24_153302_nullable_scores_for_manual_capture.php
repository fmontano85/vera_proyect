<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura manual (seccion 3.7): no hay puntaje de IA (mentions.confianza)
 * ni de Meilisearch (matches.score_meilisearch) para un registro que
 * ingreso un humano a mano - forzar un valor inventado ahi seria mentir
 * en el dato (seccion 3.5: "nunca inventar").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentions', function (Blueprint $table) {
            $table->decimal('confianza', 4, 3)->nullable()->change();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('score_meilisearch', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mentions', function (Blueprint $table) {
            $table->decimal('confianza', 4, 3)->nullable(false)->change();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->decimal('score_meilisearch', 5, 2)->nullable(false)->change();
        });
    }
};
