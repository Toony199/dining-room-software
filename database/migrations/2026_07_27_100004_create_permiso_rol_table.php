<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote M:N rol ↔ permiso (§5.4). Cambiar el pivote impacta de inmediato a los usuarios del rol.
     */
    public function up(): void
    {
        Schema::create('permiso_rol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rol_id', 'permiso_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permiso_rol');
    }
};
