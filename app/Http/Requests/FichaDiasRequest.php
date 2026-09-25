<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nueva selección de días de una ficha, hecha por el cobrador antes de cobrar (§13).
 *
 * Llega la lista completa de días que quedan, no el cambio: así el cobrador ve en pantalla lo
 * mismo que va a cobrar, y agregar y quitar son la misma operación.
 */
class FichaDiasRequest extends FormRequest
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
        return [
            'dias' => ['required', 'array', 'min:1', 'max:31'],
            'dias.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dias.required' => 'La ficha debe quedar con al menos un día. Si ya no quiere ninguno, no la cobres.',
            'dias.array' => 'La ficha debe quedar con al menos un día.',
            'dias.min' => 'La ficha debe quedar con al menos un día. Si ya no quiere ninguno, no la cobres.',
        ];
    }
}
