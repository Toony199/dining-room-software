<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convierte la tabla `users` en la "cuenta de sistema" opcional de una persona (§3.1, §5.1).
     * 1 persona → máx. 1 cuenta; 1 cuenta → exactamente un rol. Login por email + contraseña (D2).
     *
     * Las columnas se agregan siempre; las FKs solo en drivers que las admiten vía ALTER
     * (MySQL). SQLite —usado por la suite de pruebas— no soporta agregar FKs a una tabla
     * existente, por lo que ahí la integridad referencial se valida en la capa de aplicación.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->unsignedBigInteger('persona_id')->nullable()->unique()->after('id');
            $table->unsignedBigInteger('rol_id')->nullable()->after('password');
            $table->boolean('activo')->default(true)->after('rol_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('persona_id')->references('id')->on('personas')->restrictOnDelete();
                $table->foreign('rol_id')->references('id')->on('roles')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['persona_id']);
                $table->dropForeign(['rol_id']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['persona_id']);
            $table->dropColumn(['persona_id', 'rol_id', 'activo']);
            $table->string('name')->after('id');
        });
    }
};
