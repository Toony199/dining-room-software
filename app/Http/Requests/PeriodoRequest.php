<?php

namespace App\Http\Requests;

use App\Models\Periodo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Alta de un periodo de servicio y ajuste de su ventana (§6, §7).
 *
 * En el alta llegan las cuatro fechas; al editar, solo la ventana: mover las fechas de servicio
 * cambiaría los días ya creados, cada uno con su precio congelado, así que para otra semana se crea
 * otro periodo.
 */
class PeriodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function esAlta(): bool
    {
        return $this->route('periodo') === null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $ventana = [
            'ventana_inicio' => ['required', 'date_format:Y-m-d'],
            'ventana_fin' => ['required', 'date_format:Y-m-d'],
        ];

        return $this->esAlta()
            ? [
                'fecha_inicio' => ['required', 'date_format:Y-m-d'],
                'fecha_fin' => ['required', 'date_format:Y-m-d'],
                ...$ventana,
            ]
            : $ventana;
    }

    /**
     * Las reglas que relacionan unas fechas con otras (§7.2, y la decisión de que la ventana puede
     * ir antes del periodo).
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                if ($validador->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Periodo|null $periodo */
                $periodo = $this->route('periodo');

                $inicio = $periodo ? $periodo->fecha_inicio : $this->date('fecha_inicio');
                $fin = $periodo ? $periodo->fecha_fin : $this->date('fecha_fin');
                $ventanaInicio = $this->date('ventana_inicio');
                $ventanaFin = $this->date('ventana_fin');

                if ($this->esAlta() && $fin->lt($inicio)) {
                    $validador->errors()->add('fecha_fin', 'El periodo no puede terminar antes de empezar.');

                    return;
                }

                if ($ventanaFin->lt($ventanaInicio)) {
                    $validador->errors()->add('ventana_fin', 'La ventana no puede terminar antes de empezar.');

                    return;
                }

                // §7.2 pedía la ventana dentro del periodo. El negocio pide poder pedir y pagar la
                // semana anterior, para proyectar las porciones antes del lunes; lo que no tiene
                // sentido es una ventana que termine después del servicio.
                $antesDelPeriodo = $ventanaFin->lt($inicio);
                $dentroDelPeriodo = $ventanaInicio->gte($inicio) && $ventanaFin->lte($fin);

                if (! $antesDelPeriodo && ! $dentroDelPeriodo) {
                    $validador->errors()->add('ventana_inicio', sprintf(
                        'La ventana debe terminar antes de que empiece el periodo (%s) o caber dentro de él (%s a %s).',
                        $inicio->format('d/m/Y'),
                        $inicio->format('d/m/Y'),
                        $fin->format('d/m/Y'),
                    ));

                    return;
                }

                if ($this->esAlta() && Periodo::traslapados($inicio, $fin)->exists()) {
                    $validador->errors()->add('fecha_inicio', sprintf(
                        'Ya existe un periodo que cubre esos días (%s a %s).',
                        $inicio->format('d/m/Y'),
                        $fin->format('d/m/Y'),
                    ));
                }
            },
        ];
    }

    public function date($key, $format = null, $tz = null): ?Carbon
    {
        $valor = parent::date($key, $format, $tz);

        return $valor?->startOfDay();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_inicio.required' => 'Indica el primer día del periodo.',
            'fecha_inicio.date_format' => 'Indica el primer día del periodo.',
            'fecha_fin.required' => 'Indica el último día del periodo.',
            'fecha_fin.date_format' => 'Indica el último día del periodo.',
            'ventana_inicio.required' => 'Indica desde qué día se pueden generar fichas y pagar.',
            'ventana_inicio.date_format' => 'Indica desde qué día se pueden generar fichas y pagar.',
            'ventana_fin.required' => 'Indica hasta qué día se pueden generar fichas y pagar.',
            'ventana_fin.date_format' => 'Indica hasta qué día se pueden generar fichas y pagar.',
        ];
    }
}
