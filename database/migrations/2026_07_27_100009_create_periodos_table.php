<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Periodos semanales de servicio (§6, §8). Ventana única de generación y pago (D1).
     * Generación automática idempotente vía unique(fecha_inicio, fecha_fin) (§6.1).
     */
    public function up(): void
    {
        Schema::create('periodos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha_inicio');                       // lunes
            $table->date('fecha_fin');                          // viernes
            $table->date('ventana_inicio');                     // inicio de generación y pago
            $table->date('ventana_fin');                        // fin de generación y pago
            $table->enum('estado', ['BORRADOR', 'ABIERTO', 'PAGO_CERRADO', 'CONSOLIDADO'])
                ->default('BORRADOR');
            $table->boolean('generado_auto')->default(false);
            $table->timestamps();

            $table->unique(['fecha_inicio', 'fecha_fin']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos');
    }
};
