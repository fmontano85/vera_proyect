<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminos de servicio y contrato de encargo de tratamiento (seccion 3.9,
 * punto 1 del CLAUDE.md raiz - bloque B).
 *
 * documentos_legales es global (sin tenant_id): el superadmin redacta una
 * version para toda la plataforma. version null = borrador; al publicarse
 * recibe la siguiente de su tipo y ya no se modifica (lo que acepto un
 * cliente tiene que seguir existiendo tal cual).
 *
 * aceptaciones_documentos si es por tenant: quien acepto, cuando y desde
 * que IP, por cada version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_legales', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['terminos', 'contrato_encargo']);
            $table->unsignedInteger('version')->nullable();
            $table->string('titulo');
            $table->longText('contenido');
            $table->timestamp('publicado_en')->nullable();
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tipo', 'version']);
            $table->index(['tipo', 'publicado_en']);
        });

        Schema::create('aceptaciones_documentos', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // restrict: una version aceptada por alguien nunca se borra.
            $table->foreignId('documento_legal_id')->constrained('documentos_legales')->restrictOnDelete();
            $table->foreignId('aceptado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aceptado_en');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'documento_legal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aceptaciones_documentos');
        Schema::dropIfExists('documentos_legales');
    }
};
