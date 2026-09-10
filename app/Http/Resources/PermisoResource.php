<?php

namespace App\Http\Resources;

use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un permiso granular (§5.3).
 *
 * @mixin Permiso
 */
class PermisoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clave' => $this->clave,
            'descripcion' => $this->descripcion,
            'modulo' => $this->modulo,
        ];
    }
}
