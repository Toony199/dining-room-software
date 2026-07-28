<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fichas de solicitud de días (§11, §15). Estados PENDIENTE → PAGADA / VENCIDA.
     * Una sola ficha válida (PENDIENTE/PAGADA) por persona+periodo (§17.1, D4): se garantiza
     * de forma transaccional en la capa de servicio; el índice acelera esa verificación.
     */
    public function up(): void
    {
        Schema::create('fichas', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->enum('estado', ['PENDIENTE', 'PAGADA', 'VENCIDA'])->default('PENDIENTE');
            $table->decimal('total', 8, 2)->default(0);         // suma de ficha_dias.precio_snapshot
            $table->timestamp('generada_en')->useCurrent();
            $table->timestamps();

            $table->index(['persona_id', 'periodo_id']);
            $table->index(['periodo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas');
    }
};
