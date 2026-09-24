<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de tags de busqueda (delitos: hurto, estafa, etc.) para la
 * busqueda por tags - por tenant (decision del usuario 2026-09-24: cada
 * tenant administra el suyo, no un catalogo global). Sembrado con
 * defaults al crear un tenant (ver Listeners\SembrarTagsBusquedaPorDefecto),
 * el analista agrega mas desde el flujo normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_tags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_tags');
    }
};
