<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Días individuales de cada periodo (§6.2). Cada día tiene disponibilidad, festivo y
     * precio_aplicado congelado del catálogo de tarifas (§6.3, §6.5).
     */
    public function up(): void
    {
        Schema::create('dias_periodo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->constrained('periodos')->cascadeOnDelete();
            $table->date('fecha');
            $table->unsignedTinyInteger('dia_semana');          // 1=Lun … 7=Dom
            $table->boolean('disponible')->default(true);
            $table->boolean('es_festivo')->default(false);
            $table->string('motivo_indisponibilidad', 150)->nullable();
            $table->decimal('precio_aplicado', 8, 2);
            $table->timestamps();

            $table->unique(['periodo_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_periodo');
    }
};
