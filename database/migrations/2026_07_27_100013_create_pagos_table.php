<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confirmación del cobro físico (§12, §14). Un pago por ficha. El cobro ocurre fuera del
     * sistema; aquí solo se registra. monto_recibido >= total_cobrado (§12.1).
     */
    public function up(): void
    {
        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ficha_id')->unique()->constrained('fichas')->restrictOnDelete();
            $table->decimal('total_cobrado', 8, 2);
            $table->decimal('monto_recibido', 8, 2);
            $table->decimal('cambio', 8, 2)->default(0);
            $table->foreignId('cobrador_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmado_en')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
    }
};
