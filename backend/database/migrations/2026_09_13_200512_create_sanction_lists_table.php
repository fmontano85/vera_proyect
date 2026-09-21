<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanction_lists', function (Blueprint $table) {
            $table->id();
            $table->enum('codigo', ['ofac_sdn', 'un_consolidated', 'eu']);
            $table->string('version')->nullable();
            $table->timestamp('fecha_importacion')->nullable();
            $table->timestamps();

            $table->unique('codigo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanction_lists');
    }
};
