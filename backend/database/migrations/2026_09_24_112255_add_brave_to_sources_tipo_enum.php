<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 'brave' al enum de sources.tipo (Brave Search API reemplaza a
 * Google CSE como fuente activa por default - Google Custom Search JSON
 * API esta cerrada a clientes nuevos desde 2025 y Google la apaga por
 * completo el 1 de enero de 2027, ademas del bloqueo de facturacion sin
 * resolver). GoogleCseAdapter y el tipo 'cse' se quedan en el codigo por
 * si se retoma antes de esa fecha - no se elimina nada, solo se agrega.
 *
 * ->change() usa el schema builder nativo de Laravel (sin doctrine/dbal),
 * portable entre MariaDB (dev/prod) y SQLite (suite de tests, phpunit.xml
 * fuerza DB_CONNECTION=sqlite en memoria por velocidad - no viola la
 * seccion 7 del CLAUDE.md raiz, que prohibe SQLite como motor real de la
 * app, no como doble rapido y desechable para tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->enum('tipo', ['cse', 'rss', 'oficial', 'sanciones', 'brave'])->change();
        });
    }

    public function down(): void
    {
        // Si ya existe alguna Source con tipo='brave' al revertir, esto
        // falla (dato fuera del enum nuevo) - correcto que falle en vez
        // de borrar datos en silencio; hay que reasignar esas filas antes
        // de hacer rollback.
        Schema::table('sources', function (Blueprint $table) {
            $table->enum('tipo', ['cse', 'rss', 'oficial', 'sanciones'])->change();
        });
    }
};
