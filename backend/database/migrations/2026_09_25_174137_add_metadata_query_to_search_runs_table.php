<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria de la query (undecimo bloque del CLAUDE.md raiz): lo que el
 * proveedor reporta sobre como interpreto la query (Brave:
 * original/altered/spellcheck_off/search_operators) y los terminos
 * (aliases/tags) omitidos por el limite de 600 caracteres / 75 palabras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_runs', function (Blueprint $table) {
            $table->json('metadata_query')->nullable()->after('query');
        });
    }

    public function down(): void
    {
        Schema::table('search_runs', function (Blueprint $table) {
            $table->dropColumn('metadata_query');
        });
    }
};
