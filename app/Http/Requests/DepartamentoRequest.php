<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartamentoRequest extends FormRequest
{
    /**
     * Autorización. La validación de permisos granulares (§5.6) se agregará vía middleware
     * `can:departamentos.*` cuando se implemente el módulo de auth (Fase 1, paso 4).
     */
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El campo nombre es obligatorio.',
            'nombre.unique' => 'El nombre del departamento ya existe.',
            'nombre.string' => 'El campo nombre debe ser una cadena de texto.',
            'nombre.max' => 'El campo nombre no debe exceder los 120 caracteres.',
            'activo.boolean' => 'El campo activo debe ser un valor booleano.',
        ];
    }

    /**
     * Reglas de validación. En update, el `unique` ignora el propio registro enlazado por ruta.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $departamento = $this->route('departamento');
        $id = is_object($departamento) ? $departamento->id : $departamento;

        return [
            'nombre' => [
                'required',
                'string',
                'max:120',
                Rule::unique('departamentos', 'nombre')->ignore($id),
            ],
            'activo' => ['boolean'],
        ];
    }
}
