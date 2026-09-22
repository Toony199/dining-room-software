<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Diseño del gafete en SVG (§4.1).
 *
 * Aquí solo se comprueba que llegue un archivo .svg de tamaño razonable. Que sea un SVG de verdad,
 * con la proporción del gafete y sus cinco zonas, lo revisa PlantillaGafete al leerlo, que es la
 * que puede decir con precisión qué corregir.
 */
class SubirDisenoGafeteRequest extends FormRequest
{
    /**
     * Un diseño exportado pesa decenas de KB; el límite deja espacio para un logo incrustado sin
     * rebasar el `upload_max_filesize` de PHP.
     */
    public const TAMANO_MAXIMO_KB = 1024;

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
            'archivo' => ['required', 'file', 'extensions:svg', 'max:'.self::TAMANO_MAXIMO_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Selecciona el archivo SVG del diseño.',
            'archivo.file' => 'Selecciona el archivo SVG del diseño.',
            'archivo.extensions' => 'El diseño debe ser un archivo SVG.',
            'archivo.max' => 'El diseño no debe pesar más de 1 MB.',
        ];
    }
}
