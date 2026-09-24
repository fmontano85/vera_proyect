<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Busqueda por tags (definida 2026-09-24, sesion posterior a la 3.7):
 * ademas de la consulta puntual por subject, un analista puede buscar por
 * palabras clave de delitos sin apuntar a ninguna persona en concreto -
 * los resultados se cruzan despues contra TODA la lista de vigilancia del
 * tenant (MatchMentionsJob ya es agnostico de subject, no hizo falta
 * tocarlo). subject_id pasa a ser opcional en ambas tablas: null significa
 * "vino de una busqueda por tags", no de un subject.
 *
 * dias_atras: ventana de "cuantos dias atras buscar" configurable por
 * ejecucion (a diferencia de ARTICLE_WINDOW_DAYS, que es un default
 * global) - FetchArticleJob la usa en vez del default de config cuando el
 * search_result la trae.
 *
 * El unique(subject_id, url_hash) de search_results NO se toca: MySQL/
 * MariaDB tratan cada NULL como distinto en un indice unico, asi que ya
 * de por si no fuerza nada quando subject_id es null (busqueda por tags)
 * - el dedupe real para ese caso lo hace el WHERE exacto de firstOrCreate
 * en RunTagSearchJob. Intentar ampliarlo a (tenant_id, subject_id,
 * url_hash) tampoco cambiaria ese comportamiento (mismo problema de NULL)
 * y ademas rompe en MariaDB porque esa columna sostiene la foreign key de
 * subject_id (no se puede dropear el indice que la respalda sin antes
 * quitar la FK) - no vale la pena la complejidad para una proteccion que
 * de todos modos no existe a nivel de base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_runs', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->change();
            $table->json('tags')->nullable()->after('query');
            $table->unsignedSmallInteger('dias_atras')->nullable()->after('tags');
        });

        Schema::table('search_results', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->change();
            $table->unsignedSmallInteger('dias_atras')->nullable()->after('fecha_brave');
        });
    }

    public function down(): void
    {
        Schema::table('search_runs', function (Blueprint $table) {
            $table->dropColumn(['tags', 'dias_atras']);
            $table->foreignId('subject_id')->nullable(false)->change();
        });

        Schema::table('search_results', function (Blueprint $table) {
            $table->dropColumn('dias_atras');
            $table->foreignId('subject_id')->nullable(false)->change();
        });
    }
};
