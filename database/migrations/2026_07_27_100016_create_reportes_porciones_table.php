<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporte de porciones por periodo (§20). Se genera explícitamente tras el cierre, usando
     * únicamente pagos confirmados. Queda asociado al periodo y registra quién lo generó.
     */
    public function up(): void
    {
        Schema::create('reportes_porciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodo_id')->unique()->constrained('periodos')->restrictOnDelete();
            $table->foreignId('generado_por')->constrained('users')->restrictOnDelete();
            $table->timestamp('generado_en')->useCurrent();
            $table->json('datos_json')->nullable();             // snapshot: porciones por día, totales
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_porciones');
    }
};
