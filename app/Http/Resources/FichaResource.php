<?php

namespace App\Http\Resources;

use App\Models\Ficha;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de una ficha (§11 paso 4, §14).
 *
 * Lleva el desglose por día porque es lo que el colaborador ve en el kiosco y lo que el cobrador
 * cobra: cada día con el precio que se le congeló, y el total como suma de esos precios.
 *
 * @mixin Ficha
 */
class FichaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'folio' => $this->folio,
            'estado' => $this->estado,
            'total' => $this->total,
            'generada_en' => $this->generada_en,
            'persona' => $this->whenLoaded('persona', fn () => [
                'nombre_completo' => $this->persona->nombre_completo,
                'numero_empleado' => $this->persona->numero_empleado,
            ]),
            'periodo' => $this->whenLoaded('periodo', fn () => [
                'id' => $this->periodo->id,
                'fecha_inicio' => $this->periodo->fecha_inicio?->toDateString(),
                'fecha_fin' => $this->periodo->fecha_fin?->toDateString(),
            ]),
            'dias' => $this->whenLoaded('dias', fn () => $this->dias
                ->sortBy(fn ($dia) => $dia->diaPeriodo?->fecha)
                ->values()
                ->map(fn ($dia) => [
                    'dia_periodo_id' => $dia->dia_periodo_id,
                    'fecha' => $dia->diaPeriodo?->fecha?->toDateString(),
                    'dia_semana' => $dia->diaPeriodo?->dia_semana,
                    'precio' => $dia->precio_snapshot,
                ])),
        ];
    }
}
