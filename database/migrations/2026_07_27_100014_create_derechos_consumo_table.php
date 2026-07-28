<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derechos de consumo por día pagado (§2.3, §15). Se crean SOLO al confirmar el pago (§2.2).
     * Intransferibles e irreutilizables. unique(persona, día) evita dos derechos el mismo día.
     */
    public function up(): void
    {
        Schema::create('derechos_consumo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->foreignId('dia_periodo_id')->constrained('dias_periodo')->restrictOnDelete();
            $table->foreignId('ficha_id')->constrained('fichas')->restrictOnDelete();
            $table->enum('estado', ['VIGENTE', 'UTILIZADO', 'VENCIDO'])->default('VIGENTE');
            $table->decimal('precio_pagado', 8, 2);
            $table->timestamps();

            $table->unique(['persona_id', 'dia_periodo_id']);
            $table->index(['periodo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('derechos_consumo');
    }
};
