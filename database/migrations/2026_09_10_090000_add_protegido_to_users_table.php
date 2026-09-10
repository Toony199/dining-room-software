<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca la cuenta administradora de arranque como protegida.
     *
     * El rol Administrador ya estaba protegido, pero la cuenta que lo usa no: desde el módulo
     * de personas se podía dar de baja a su persona (o suspenderla desde usuarios, o cambiarle
     * el rol) y la instalación quedaba sin nadie capaz de entrar a administrarla.
     *
     * No está en el #[Fillable] del modelo a propósito: la bandera la pone el seeder, nunca un
     * payload de la API.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('protegido')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('protegido');
        });
    }
};
