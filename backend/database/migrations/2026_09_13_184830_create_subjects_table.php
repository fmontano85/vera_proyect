<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->enum('tipo', ['natural', 'juridica']);
            $table->string('nombre_canonico');
            $table->string('documento')->nullable();
            $table->enum('nivel_riesgo', ['bajo', 'medio', 'alto'])->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'nombre_canonico']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
