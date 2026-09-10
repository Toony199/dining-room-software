<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermisoResource;
use App\Models\Permiso;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PermisosController extends Controller
{
    /**
     * Catálogo completo de permisos (§5.3).
     *
     * No se pagina: es un catálogo fijo de unas decenas de claves que la pantalla de
     * asignación necesita entero para pintar las casillas agrupadas por módulo. Tampoco tiene
     * alta ni baja por API: los permisos los define el código conforme se agregan módulos, no
     * el usuario final. Se dan de alta con PermisoSeeder.
     */
    public function index(): AnonymousResourceCollection
    {
        $permisos = Permiso::query()
            ->orderBy('modulo')
            ->orderBy('clave')
            ->get();

        return PermisoResource::collection($permisos);
    }
}
