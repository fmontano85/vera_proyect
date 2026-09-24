<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captura manual (seccion 3.7): una mention manual no tiene Article detras
 * (el fetch automatico fallo, por eso es GAP) - article_id pasa a
 * nullable. search_result_id es el enlace confiable para que el frontend
 * pida "las menciones de este resultado" sin depender de un article_id
 * que puede ser null; se llena siempre desde ahora (automatico o manual),
 * nullable solo por las mentions que ya existian antes de esta migracion
 * (sin backfill - decision del usuario 2026-09-24, eran datos de prueba).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentions', function (Blueprint $table) {
            $table->foreignId('article_id')->nullable()->change();

            $table->foreignId('search_result_id')->nullable()->after('article_id')
                ->constrained()->restrictOnDelete();
            $table->enum('origen', ['automatico', 'manual'])->default('automatico')->after('confianza');
            $table->foreignId('creado_por')->nullable()->after('origen')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mentions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('search_result_id');
            $table->dropConstrainedForeignId('creado_por');
            $table->dropColumn('origen');
            $table->foreignId('article_id')->nullable(false)->change();
        });
    }
};
