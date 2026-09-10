<?php

namespace App\Http\Resources;

use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una persona tal como la necesita el select del alta de cuentas: lo justo para reconocerla.
 *
 * Sin departamento, estado ni fechas: quien crea cuentas no necesita `colaboradores.ver` para
 * elegir a quién dársela, y tampoco debería recibir el expediente completo.
 *
 * @mixin Persona
 */
class PersonaDisponibleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_empleado' => $this->numero_empleado,
            'nombre_completo' => $this->nombre_completo,
        ];
    }
}
