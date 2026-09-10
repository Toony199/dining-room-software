<?php

namespace App\Http\Resources;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rol tal como lo necesita el formulario de cuentas: lo justo para elegirlo.
 *
 * Deliberadamente sin descripción, sin conteos y sin permisos. Quien administra cuentas
 * necesita poder asignar un rol, no ver cómo está configurado; para eso está `roles.ver` y
 * RolResource.
 *
 * @mixin Rol
 */
class RolAsignableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
        ];
    }
}
