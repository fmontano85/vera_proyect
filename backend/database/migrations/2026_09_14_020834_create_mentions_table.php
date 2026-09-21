<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global (sin tenant_id), igual que articles: una mencion es un hecho
     * sobre el CONTENIDO del articulo (quien aparece, con que rol), no
     * sobre ningun tenant en particular. El aislamiento de tenant entra
     * en la tabla matches, que vincula una mencion con el subject de un
     * tenant especifico.
     */
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('nombre_extraido');
            $table->enum('rol', ['imputado', 'condenado', 'victima', 'testigo', 'otro']);
            $table->json('delitos')->nullable();
            $table->decimal('confianza', 4, 3);
            $table->timestamps();

            // unica, no solo indice: permite firstOrCreate() idempotente
            // en ExtractEntitiesJob (seccion 7 - reintentos no duplican).
            $table->unique(['article_id', 'nombre_extraido', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};
