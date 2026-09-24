<?php

namespace App\Http\Resources;

use App\Models\Tarifa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de una tarifa (§18).
 *
 * `vigente` y `programada` se calculan contra la fecha de hoy en el servidor, y no se guardan: el
 * estado de una tarifa cambia solo con el paso del tiempo.
 *
 * @mixin Tarifa
 */
class TarifaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'precio' => $this->precio,
            'vigente_desde' => $this->vigente_desde?->toDateString(),
            'vigente_hasta' => $this->vigente_hasta?->toDateString(),
            'vigente' => $this->estaVigente(),
            'programada' => $this->estaProgramada(),
            'creado_por' => $this->whenLoaded('creadoPor', fn () => $this->creadoPor?->persona?->nombre_completo),
            'created_at' => $this->created_at,
        ];
    }
}
