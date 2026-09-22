<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Personas cuyos gafetes se van a imprimir juntos en una hoja (§4.1).
 *
 * No se comprueba aquí que las personas existan ni que tengan gafete: el controlador las separa
 * y devuelve el motivo de cada una que quedó fuera, porque enterarse de eso antes de gastar la
 * hoja es justamente el punto de la impresión en lote.
 */
class ImprimirGafetesRequest extends FormRequest
{
    /**
     * Tope de personas por hoja de impresión: caben nueve gafetes por hoja, así que son unas
     * siete hojas. Es lo que evita que una selección hecha sin mirar pida miles de registros y
     * sus fotografías de un solo golpe.
     */
    public const MAXIMO = 60;

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
            'personas' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO],
            'personas.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'personas.required' => 'Selecciona al menos una persona.',
            'personas.array' => 'Selecciona al menos una persona.',
            'personas.min' => 'Selecciona al menos una persona.',
            'personas.max' => 'No se pueden imprimir más de '.self::MAXIMO.' gafetes a la vez.',
            'personas.*.integer' => 'La selección contiene una persona inválida.',
            'personas.*.min' => 'La selección contiene una persona inválida.',
            'personas.*.distinct' => 'La selección repite a una persona.',
        ];
    }
}
