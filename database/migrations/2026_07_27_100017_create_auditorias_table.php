<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de acciones relevantes (§19). Append-only: solo created_at. user_id NULL indica
     * una acción ejecutada por un proceso del sistema (jobs programados).
     */
    public function up(): void
    {
        Schema::create('auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 80);                       // ej. periodo.abrir, pago.confirmar
            $table->string('auditable_type')->nullable();       // relación polimórfica (morph)
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('datos')->nullable();                  // contexto relevante de la operación
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('accion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditorias');
    }
};
