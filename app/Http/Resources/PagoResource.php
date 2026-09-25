<?php

namespace App\Http\Resources;

use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un pago, que es también el contenido del ticket (§14): folio, colaborador,
 * periodo, días pagados, total, lo recibido, el cambio, cuándo se confirmó y quién lo cobró.
 *
 * @mixin Pago
 */
class PagoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'total_cobrado' => $this->total_cobrado,
            'monto_recibido' => $this->monto_recibido,
            'cambio' => $this->cambio,
            'confirmado_en' => $this->confirmado_en,
            'cobrador' => $this->whenLoaded('cobrador', fn () => $this->cobrador?->persona?->nombre_completo ?? $this->cobrador?->email),
            'ficha' => new FichaResource($this->whenLoaded('ficha')),
        ];
    }
}
