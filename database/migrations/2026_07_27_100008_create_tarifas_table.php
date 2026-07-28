<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarifas por día (§6.4, §18). Precio general para todos, con historial por vigencia.
     * El precio se COPIA a dias_periodo.precio_aplicado; no se referencia por FK viva (§6.5).
     */
    public function up(): void
    {
        Schema::create('tarifas', function (Blueprint $table) {
            $table->id();
            $table->decimal('precio', 8, 2);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();          // NULL = vigente actual
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas');
    }
};
