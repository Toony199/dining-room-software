<?php

namespace App\Http\Resources;

use App\Models\Gafete;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un gafete en el historial (§4.3).
 *
 * Sin `qr_token` a propósito: el historial lo consulta quien tiene `gafetes.ver`, y con el token
 * se puede fabricar un gafete que funcione. El token solo sale por GafeteImpresionResource.
 *
 * @mixin Gafete
 */
class GafeteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'persona_id' => $this->persona_id,
            'estado' => $this->estado,
            'emitido_en' => $this->emitido_en,
        ];
    }
}
