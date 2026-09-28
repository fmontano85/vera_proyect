<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora por tenant (seccion 3.9, punto 7): el admin de cada tenant
 * consulta solo la suya y el superadmin filtra por tenant. Sin clave
 * foranea a proposito: al dar de baja un tenant (bloque D) se conserva el
 * registro "tenant dado de baja" aunque la fila de tenants ya no exista.
 *
 * Relleno de registros existentes con subconsultas correlacionadas
 * (portables SQLite/MariaDB, sin SQL especifico de un driver).
 */
return new class extends Migration
{
    /** @var array<class-string, string> modelo auditado => tabla con tenant_id */
    private const TABLAS_CON_TENANT = [
        'App\Models\Subject' => 'subjects',
        'App\Models\SubjectAlias' => 'subject_aliases',
        'App\Models\MentionMatch' => 'matches',
        'App\Models\SanctionMatch' => 'sanction_matches',
        'App\Models\SearchTag' => 'search_tags',
        'App\Models\FrecuenciaSeguimiento' => 'frecuencias_seguimiento',
        'App\Models\User' => 'users',
    ];

    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'created_at']);
        });

        DB::table('activity_log')
            ->where('subject_type', 'Stancl\Tenancy\Database\Models\Tenant')
            ->update(['tenant_id' => DB::raw('subject_id')]);

        foreach (self::TABLAS_CON_TENANT as $modelo => $tabla) {
            DB::table('activity_log')
                ->whereNull('tenant_id')
                ->where('subject_type', $modelo)
                ->update(['tenant_id' => DB::table($tabla)
                    ->select('tenant_id')
                    ->whereColumn("{$tabla}.id", 'activity_log.subject_id')
                    ->limit(1)]);
        }

        // Eventos de jobs sin causante que guardaron el tenant en properties
        // (ej. SendAlertJob 'alertas_enviadas'). Se lee en PHP: extraer
        // JSON por SQL no es portable entre SQLite y MariaDB.
        DB::table('activity_log')->whereNull('tenant_id')->whereNotNull('properties')
            ->orderBy('id')->select(['id', 'properties'])
            ->each(function (object $fila) {
                $tenantId = json_decode((string) $fila->properties, true)['tenant_id'] ?? null;
                if (is_string($tenantId)) {
                    DB::table('activity_log')->where('id', $fila->id)->update(['tenant_id' => $tenantId]);
                }
            });

        DB::table('activity_log')
            ->whereNull('tenant_id')
            ->where('causer_type', 'App\Models\User')
            ->update(['tenant_id' => DB::table('users')
                ->select('tenant_id')
                ->whereColumn('users.id', 'activity_log.causer_id')
                ->limit(1)]);
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropColumn('tenant_id');
        });
    }
};
