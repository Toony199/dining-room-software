<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca los roles que el sistema necesita para funcionar y que la interfaz no debe dejar
     * tocar (§5.2).
     *
     * El caso concreto es el rol de acceso total: si alguien pudiera quitarle permisos o
     * desactivarlo, dejaría la instalación sin nadie capaz de administrarla y sin forma de
     * arreglarlo desde la propia aplicación.
     *
     * No está en el #[Fillable] del modelo a propósito: la bandera la pone el seeder, nunca
     * un payload de la API.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('protegido')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('protegido');
        });
    }
};
