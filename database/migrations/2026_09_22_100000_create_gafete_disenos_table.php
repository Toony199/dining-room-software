<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diseños del gafete subidos desde la aplicación (§4.1). Hay un solo formato para todos (§4):
     * a lo más un diseño activo, y mientras no haya ninguno se usa el que trae el proyecto
     * (resources/gafetes/plantilla-gafete.svg). Los diseños no se borran: quedan como historial
     * y se puede volver a activar uno anterior.
     */
    public function up(): void
    {
        Schema::create('gafete_disenos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_archivo', 150);          // como se llamaba el archivo subido
            $table->string('archivo_path');                 // SVG ya limpio y sin zonas, en el disco privado
            $table->json('zonas');                          // posición de cada dato, en mm del gafete
            $table->json('advertencias');                   // lo que se quitó o puede salir distinto
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('activo')->default(false);      // recién subido es borrador hasta activarlo
            $table->timestamp('activado_en')->nullable();
            $table->timestamps();

            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gafete_disenos');
    }
};
