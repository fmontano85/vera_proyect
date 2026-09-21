<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin tenant_id: sources es un catalogo global administrado por
     * superadmin (seccion 3.3 del CLAUDE.md raiz dice "global"). No se
     * agrega una columna tenant_id nullable "por si acaso" para un
     * eventual origen privado de un tenant - eso se modela cuando exista
     * esa necesidad real, no antes (si se agrega sin scope que la haga
     * cumplir, es una fuga de datos servida en bandeja).
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->enum('tipo', ['cse', 'rss', 'oficial', 'sanciones']);
            $table->json('config');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
