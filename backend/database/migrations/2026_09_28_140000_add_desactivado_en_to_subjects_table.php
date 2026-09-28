<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando termino la relacion con la persona (seccion 3.9, punto 2): el
 * plazo legal de retencion (Art. 26, Decreto 426: 15 anios) corre desde el
 * fin de la relacion comercial, que en VERA es la desactivacion. Las
 * personas ya inactivas toman updated_at como mejor aproximacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->timestamp('desactivado_en')->nullable()->after('activo');
            $table->index(['tenant_id', 'activo', 'desactivado_en']);
        });

        DB::table('subjects')->where('activo', false)->update(['desactivado_en' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'activo', 'desactivado_en']);
            $table->dropColumn('desactivado_en');
        });
    }
};
