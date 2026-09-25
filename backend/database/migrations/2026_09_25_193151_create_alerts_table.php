<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seccion 3.3 (alerts) + 3.8: una alerta por subject y vencimiento. El
 * unique es la idempotencia del job diario - correrlo varias veces el
 * mismo dia no duplica alertas; un vencimiento nuevo (tras marcar
 * seguimiento) si genera una alerta nueva. enviado_en null = pendiente
 * de incluir en el correo de resumen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->enum('tipo', ['seguimiento_pendiente']);
            $table->morphs('alertable');
            $table->date('vencimiento');
            $table->string('canal', 20)->default('correo');
            $table->timestamp('enviado_en')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'tipo', 'alertable_type', 'alertable_id', 'vencimiento'], 'alerts_unica_por_vencimiento');
            $table->index(['tenant_id', 'enviado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
