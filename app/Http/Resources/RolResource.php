<?php

namespace App\Http\Resources;

use App\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un rol (§5.2).
 *
 * @mixin Rol
 */
class RolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'activo' => $this->activo,
            // La UI lo usa para deshabilitar la edición y el switch de baja (§5.2). El backend
            // rechaza igualmente: ocultar el botón no es la regla.
            'protegido' => $this->protegido,
            // Los permisos solo viajan si el query los cargó: el index no necesita las ~27
            // filas del pivote por cada rol, pero el formulario de edición sí.
            'permisos' => PermisoResource::collection($this->whenLoaded('permisos')),
            'permisos_count' => $this->whenCounted('permisos'),
            // El frontend lo usa para deshabilitar el switch de baja: un rol con usuarios
            // asignados no puede desactivarse (§5.5).
            'usuarios_count' => $this->whenCounted('usuarios'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
