<?php

namespace App\Http\Requests;

use App\Models\Tarifa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta de un precio por día (§6.4, §18).
 *
 * No hay edición ni baja: el catálogo es histórico. Para corregir o cambiar el precio se registra
 * otro, y el anterior queda cerrado el día previo.
 */
class TarifaRequest extends FormRequest
{
    /** Tope de cordura: la columna admite más, pero un precio así es un dedazo, no una tarifa. */
    public const PRECIO_MAXIMO = 9999.99;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'precio' => ['required', 'numeric', 'min:0.01', 'max:'.self::PRECIO_MAXIMO],
            // Se admite programar un precio para más adelante, pero no cambiar el pasado: lo que
            // ya se cobró quedó congelado en los días de cada periodo (§6.5).
            'vigente_desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * La tarifa nueva tiene que empezar después de la última registrada; si no, habría dos precios
     * para el mismo día y dejaría de saberse cuál rige.
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                $ultima = Tarifa::ultima();

                if (! $ultima || ! $this->filled('vigente_desde')) {
                    return;
                }

                if (! $ultima->vigente_desde->lt($this->date('vigente_desde'))) {
                    $validador->errors()->add('vigente_desde', sprintf(
                        'La tarifa vigente empezó el %s: la nueva debe empezar después.',
                        $ultima->vigente_desde->format('d/m/Y'),
                    ));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'precio.required' => 'Escribe el precio del día.',
            'precio.numeric' => 'El precio debe ser una cantidad.',
            'precio.min' => 'El precio debe ser mayor que cero.',
            'precio.max' => 'El precio no puede pasar de $'.number_format(self::PRECIO_MAXIMO, 2).'.',
            'vigente_desde.required' => 'Indica desde qué día rige el precio.',
            'vigente_desde.date_format' => 'Indica desde qué día rige el precio.',
            'vigente_desde.after_or_equal' => 'El precio puede empezar hoy o más adelante, no en una fecha pasada.',
        ];
    }
}
