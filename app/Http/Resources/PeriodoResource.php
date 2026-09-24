<?php

namespace App\Http\Resources;

use App\Models\Periodo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un periodo de servicio (§6, §8).
 *
 * `ventana_vigente`, `ventana_vencida` y `admite_fichas` se calculan contra la fecha de hoy en el
 * servidor y no se guardan: dependen del día en que se pregunta, no del registro. `admite_fichas`
 * es lo que de verdad decide si el kiosco acepta una ficha, porque exige periodo abierto y ventana
 * corriendo (§17.4).
 *
 * @mixin Periodo
 */
class PeriodoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'ventana_inicio' => $this->ventana_inicio?->toDateString(),
            'ventana_fin' => $this->ventana_fin?->toDateString(),
            'estado' => $this->estado,
            'generado_auto' => $this->generado_auto,
            'ventana_vigente' => $this->ventanaVigente(),
            'ventana_vencida' => $this->ventanaVencida(),
            'admite_fichas' => $this->admiteFichas(),
            'dias_disponibles' => $this->whenCounted('diasDisponibles'),
            'dias' => DiaPeriodoResource::collection($this->whenLoaded('dias')),
            'created_at' => $this->created_at,
        ];
    }
}
