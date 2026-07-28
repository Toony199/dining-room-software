<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote ficha × día (selección editable mientras la ficha está PENDIENTE, §13).
     * El día debe pertenecer al mismo periodo y estar disponible (validación en servicio).
     *
     * `precio_snapshot` congela el precio de CADA día seleccionado, copiado de
     * dias_periodo.precio_aplicado al momento de la selección (§6.5). Soporta precios
     * por día distintos (§6.4) y blinda el histórico aunque luego se edite el catálogo;
     * fichas.total es la suma de estos snapshots.
     */
    public function up(): void
    {
        Schema::create('ficha_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ficha_id')->constrained('fichas')->cascadeOnDelete();
            $table->foreignId('dia_periodo_id')->constrained('dias_periodo')->restrictOnDelete();
            $table->decimal('precio_snapshot', 8, 2);           // precio congelado de este día
            $table->timestamps();

            $table->unique(['ficha_id', 'dia_periodo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_dias');
    }
};
