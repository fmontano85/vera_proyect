<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanction_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sanction_list_id')->constrained()->cascadeOnDelete();
            // Id de la fuente original (ent_num en OFAC, etc.) - sin esto
            // ImportSanctionListsJob no puede hacer upsert idempotente
            // (seccion 7: "cada job debe ser idempotente y reintentable").
            $table->string('external_id')->nullable();
            $table->string('nombre');
            $table->json('aliases')->nullable();
            $table->string('tipo')->nullable();
            $table->string('programa')->nullable();
            $table->string('pais')->nullable();
            $table->json('raw_json');
            $table->timestamps();

            $table->unique(['sanction_list_id', 'external_id']);
            $table->index(['sanction_list_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanction_entries');
    }
};
