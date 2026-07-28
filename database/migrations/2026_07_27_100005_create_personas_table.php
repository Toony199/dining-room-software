<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personas registradas (§3). Número de empleado único e inmutable (§3.2); baja lógica (§3.3).
     */
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('numero_empleado', 30)->unique();   // inmutable, nunca reasignado
            $table->string('nombre', 150);
            $table->foreignId('departamento_id')->constrained('departamentos')->restrictOnDelete();
            $table->string('foto_path', 255)->nullable();       // fotografía opcional
            $table->enum('estado', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');
            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
