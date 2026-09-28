<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila unica global (id=1 siempre) - el modo de descarga de la lista OFAC
 * es UNA sola configuracion para todo el sistema, no por tenant: la lista
 * (sanction_entries) es un catalogo global compartido (seccion 3.1/3.3 del
 * CLAUDE.md raiz), asi que no tiene sentido que un tenant la quiera
 * automatica y otro manual al mismo tiempo. Decision del usuario 2026-09-28.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_sanciones', function (Blueprint $table) {
            $table->id();
            $table->enum('modo_descarga_ofac', ['manual', 'automatico'])->default('automatico');
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_sanciones');
    }
};
