<?php

namespace App\Http\Resources;

use App\Models\Gafete;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que necesita la vista de impresión para dibujar el gafete (§4.1).
 *
 * Es el único recurso que expone `qr_token`, y solo lo sirve el endpoint de impresión, que exige
 * `gafetes.emitir` o `gafetes.reimprimir`.
 *
 * @mixin Gafete
 */
class GafeteImpresionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'emitido_en' => $this->emitido_en,
            'qr_token' => $this->qr_token,
            'persona' => [
                'numero_empleado' => $this->persona->numero_empleado,
                'nombre_completo' => $this->persona->nombre_completo,
                'nombre_gafete' => $this->persona->nombre_gafete,
                'departamento' => $this->persona->departamento?->nombre,
                'foto_url' => $this->persona->urlDeFoto(),
            ],
        ];
    }
}
