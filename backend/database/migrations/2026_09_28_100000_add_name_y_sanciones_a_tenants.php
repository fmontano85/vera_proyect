<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * name: identificar al tenant en el panel de superadmin - hoy ningun
 * tenant tiene nombre (Tenant::create() se llama sin argumentos en todo
 * el codigo). sanciones_habilitado: por defecto false (decision del
 * usuario 2026-09-28) - solo el superadmin la activa por tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->boolean('sanciones_habilitado')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['name', 'sanciones_habilitado']);
        });
    }
};
