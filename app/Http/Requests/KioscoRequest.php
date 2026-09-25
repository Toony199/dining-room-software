<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que llega desde el kiosco (§10, §11).
 *
 * Es la única parte de la API sin sesión: el colaborador se identifica con el QR de su gafete y
 * nada más (§10.1). Por eso aquí solo se comprueba la forma de lo que llega; que el gafete exista,
 * esté activo y su persona también, lo resuelve el controlador contra la base.
 */
class KioscoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $reglas = [
            'qr_token' => ['required', 'string', 'max:40'],
        ];

        // Al generar la ficha llegan además los días elegidos.
        return $this->isMethod('post') && $this->routeIs('kiosco.fichas')
            ? [
                ...$reglas,
                'dias' => ['required', 'array', 'min:1', 'max:31'],
                'dias.*' => ['integer', 'min:1', 'distinct'],
            ]
            : $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'qr_token.required' => 'Escanea tu gafete para continuar.',
            'qr_token.string' => 'Escanea tu gafete para continuar.',
            'dias.required' => 'Elige al menos un día para comer.',
            'dias.array' => 'Elige al menos un día para comer.',
            'dias.min' => 'Elige al menos un día para comer.',
        ];
    }
}
