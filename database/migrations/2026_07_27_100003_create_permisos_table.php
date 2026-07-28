<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permisos granulares (§5.3). Cada permiso es una acción concreta validada en backend (§5.6).
     */
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 80)->unique();       // ej. pagos.confirmar
            $table->string('descripcion', 255)->nullable();
            $table->string('modulo', 40);                 // agrupador para UI
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos');
    }
};
