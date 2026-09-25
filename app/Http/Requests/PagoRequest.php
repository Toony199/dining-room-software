<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmación del cobro físico (§12.1).
 *
 * Aquí solo se comprueba que el monto sea una cantidad válida. Que alcance para pagar la ficha lo
 * decide el modelo, que es quien conoce el total y devuelve el cambio.
 */
class PagoRequest extends FormRequest
{
    /** Tope de cordura: un billete de más es normal; cien mil pesos es un dedazo. */
    public const MONTO_MAXIMO = 99999.99;

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
            'monto_recibido' => ['required', 'numeric', 'min:0.01', 'max:'.self::MONTO_MAXIMO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto_recibido.required' => 'Escribe el monto que recibiste.',
            'monto_recibido.numeric' => 'El monto debe ser una cantidad.',
            'monto_recibido.min' => 'El monto debe ser mayor que cero.',
            'monto_recibido.max' => 'Ese monto es demasiado alto: revisa la cantidad.',
        ];
    }
}
