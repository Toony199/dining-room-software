<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registros cargados a mano guardaron el texto 'null' o una cadena vacía en `foto_path` en
     * lugar de NULL. Con la carga de fotografías (§3.1) esa columna empieza a usarse, y un valor
     * así parecería una ruta de archivo.
     */
    public function up(): void
    {
        DB::table('personas')->whereIn('foto_path', ['null', ''])->update(['foto_path' => null]);
    }

    /**
     * Sin vuelta atrás: no se puede saber qué filas tenían 'null' y cuáles cadena vacía, y
     * ninguno de los dos valores era una foto.
     */
    public function down(): void
    {
        //
    }
};
