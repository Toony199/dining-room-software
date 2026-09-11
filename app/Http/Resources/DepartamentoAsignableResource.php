<?php

namespace App\Http\Resources;

use App\Models\Departamento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un departamento tal como lo necesita el formulario de personas: lo justo para elegirlo.
 *
 * Quien da de alta personal necesita asignar un departamento, no administrar el catálogo; para
 * eso está `departamentos.ver` y DepartamentoResource.
 *
 * @mixin Departamento
 */
class DepartamentoAsignableResource extends JsonResource
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
