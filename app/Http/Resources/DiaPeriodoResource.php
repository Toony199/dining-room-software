<?php

namespace App\Http\Resources;

use App\Models\DiaPeriodo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de un día del periodo (§6.2).
 *
 * @mixin DiaPeriodo
 */
class DiaPeriodoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'periodo_id' => $this->periodo_id,
            'fecha' => $this->fecha?->toDateString(),
            'dia_semana' => $this->dia_semana,
            'disponible' => $this->disponible,
            'es_festivo' => $this->es_festivo,
            'motivo_indisponibilidad' => $this->motivo_indisponibilidad,
            'precio_aplicado' => $this->precio_aplicado,
        ];
    }
}
