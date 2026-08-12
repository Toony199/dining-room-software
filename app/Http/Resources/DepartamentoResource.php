<?php

namespace App\Http\Resources;

use App\Models\Departamento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un departamento (§3.4).
 *
 * El contrato de la API se declara aquí y no en la migración: agregar una columna a la tabla
 * no la publica automáticamente, y renombrarla no rompe al frontend sin aviso.
 *
 * @mixin Departamento
 */
class DepartamentoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'activo' => $this->activo,
            // `whenCounted` solo incluye la clave si el query hizo withCount('personas');
            // así el index puede mostrar el conteo sin que show() cargue de más.
            'personas_count' => $this->whenCounted('personas'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
