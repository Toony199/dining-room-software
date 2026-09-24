<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ajuste de un día del periodo (§6.3, §8.1): festivos, excepciones y su precio.
 *
 * Solo se puede mientras el periodo está en BORRADOR; eso lo comprueba el controlador, porque
 * depende del periodo y no de lo que llega en la petición.
 */
class DiaPeriodoRequest extends FormRequest
{
    public const MOTIVO_MAXIMO = 150;

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
            'disponible' => ['required', 'boolean'],
            'es_festivo' => ['required', 'boolean'],
            'motivo_indisponibilidad' => ['nullable', 'string', 'max:'.self::MOTIVO_MAXIMO],
            // El precio nace copiado del catálogo (§6.5), pero en borrador se puede ajustar: §8.1
            // permite modificar precios mientras el periodo se configura.
            'precio_aplicado' => ['required', 'numeric', 'min:0.01', 'max:'.TarifaRequest::PRECIO_MAXIMO],
        ];
    }

    /**
     * Un día que sigue disponible con un motivo de ausencia, o uno retirado sin decir por qué,
     * dejan un registro que nadie sabe leer después.
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                if ($this->boolean('disponible') && filled($this->input('motivo_indisponibilidad'))) {
                    $validador->errors()->add(
                        'motivo_indisponibilidad',
                        'El día está disponible: quita el motivo o marca el día como no disponible.',
                    );
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
            'disponible.required' => 'Indica si el día tiene servicio.',
            'es_festivo.required' => 'Indica si el día es festivo.',
            'motivo_indisponibilidad.max' => 'El motivo no debe pasar de '.self::MOTIVO_MAXIMO.' caracteres.',
            'precio_aplicado.required' => 'Escribe el precio del día.',
            'precio_aplicado.numeric' => 'El precio debe ser una cantidad.',
            'precio_aplicado.min' => 'El precio debe ser mayor que cero.',
        ];
    }
}
