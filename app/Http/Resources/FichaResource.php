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
                // Los días de la semana completa, para que la caja pueda agregar o quitar (§13).
                // Los no disponibles viajan marcados, no escondidos.
                'dias' => $this->periodo->relationLoaded('dias')
                    ? $this->periodo->dias->map(fn ($dia) => [
                        'id' => $dia->id,
                        'fecha' => $dia->fecha?->toDateString(),
                        'dia_semana' => $dia->dia_semana,
                        'disponible' => $dia->disponible,
                        'motivo_indisponibilidad' => $dia->motivo_indisponibilidad,
                        'precio_aplicado' => $dia->precio_aplicado,
                    ])->all()
                    : null,
            ]),
            // El pago es el respaldo de la operación (§14): viaja con la ficha para que la caja y
            // el kiosco puedan mostrar el ticket sin pedirlo aparte.
            'pago' => $this->whenLoaded('pago', fn () => $this->pago === null ? null : [
                'total_cobrado' => $this->pago->total_cobrado,
                'monto_recibido' => $this->pago->monto_recibido,
                'cambio' => $this->pago->cambio,
                'confirmado_en' => $this->pago->confirmado_en,
                'cobrador' => $this->pago->relationLoaded('cobrador')
                    ? ($this->pago->cobrador?->persona?->nombre_completo ?? $this->pago->cobrador?->email)
                    : null,
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
