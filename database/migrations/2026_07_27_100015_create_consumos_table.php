<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro del uso de un derecho en el comedor (§16). Un consumo por derecho; marca el
     * derecho como UTILIZADO. Guarda el gafete usado al validar.
     */
    public function up(): void
    {
        Schema::create('consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('derecho_consumo_id')->unique()
                ->constrained('derechos_consumo')->restrictOnDelete();
            $table->foreignId('gafete_id')->constrained('gafetes')->restrictOnDelete();
            $table->timestamp('consumido_en')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos');
    }
};
