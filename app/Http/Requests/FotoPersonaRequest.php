<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fotografía de una persona (§3.1), tomada con la cámara al darla de alta o al editarla.
 *
 * El navegador ya la entrega recortada, reducida y en WebP, así que aquí solo se comprueba que
 * sea una imagen de verdad y de tamaño razonable. Se aceptan JPG y PNG como respaldo para los
 * navegadores que no saben codificar WebP.
 */
class FotoPersonaRequest extends FormRequest
{
    /**
     * Tipos aceptados, comprobados contra el contenido del archivo y no contra su extensión.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['image/webp', 'image/jpeg', 'image/png'];

    /**
     * Límite en KB. La foto que prepara el navegador pesa bastante menos; queda además por debajo
     * del `upload_max_filesize` de PHP, que si se rebasa rechaza el archivo antes de llegar aquí.
     */
    public const TAMANO_MAXIMO_KB = 1024;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function reglas(bool $obligatoria): array
    {
        return [
            'foto' => [
                $obligatoria ? 'required' : 'nullable',
                'file',
                'mimetypes:'.implode(',', self::TIPOS),
                'max:'.self::TAMANO_MAXIMO_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'foto.required' => 'Toma o selecciona una fotografía.',
            'foto.file' => 'La fotografía no se recibió correctamente. Vuelve a intentarlo.',
            'foto.uploaded' => 'La fotografía no se pudo subir; probablemente pesa demasiado.',
            'foto.mimetypes' => 'La fotografía debe ser una imagen WebP, JPG o PNG.',
            'foto.max' => 'La fotografía no debe pesar más de 1 MB.',
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::reglas(obligatoria: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajes();
    }
}
