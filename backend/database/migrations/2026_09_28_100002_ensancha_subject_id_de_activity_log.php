<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bug real encontrado en MariaDB (SQLite no lo detecta - guarda cualquier
 * cosa en una columna sin tipo estricto): activity_log.subject_id nacio
 * como unsignedBigInteger (spatie/laravel-activitylog::nullableMorphs,
 * migracion original) porque todo lo que se auditaba hasta ahora tenia
 * PK entero. Tenant (stancl/tenancy) usa PK string/UUID - registrar
 * actividad sobre un tenant (panel de superadmin, seccion 3.2) truena
 * con "Data truncated for column 'subject_id'" en MariaDB con modo
 * estricto. string(36) alcanza para ambos casos (un id entero impreso
 * como texto no pierde nada) sin romper lo que ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('subject_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
