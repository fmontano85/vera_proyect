<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_runs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // restrictOnDelete: search_runs es evidencia de que se corrio
            // una busqueda (seccion 1/3.6) - no se borra en cascada al
            // borrar el subject o la source.
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->text('query');
            $table->json('resultados')->nullable();
            $table->decimal('costo', 8, 4)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_runs');
    }
};
