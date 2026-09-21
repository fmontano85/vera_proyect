<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanction_matches', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // restrictOnDelete (no cascade): un sanction_match resuelto es
            // evidencia auditable (seccion 1/3.6) - borrar el subject o la
            // sanction_entry no debe poder arrastrar ese registro.
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('sanction_entry_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 5, 2);
            $table->enum('estado', ['pendiente', 'confirmado', 'falso_positivo', 'homonimo'])->default('pendiente');
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_en')->nullable();
            $table->timestamps();

            $table->unique(['subject_id', 'sanction_entry_id']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanction_matches');
    }
};
