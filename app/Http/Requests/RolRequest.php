<?php

namespace App\Http\Requests;

use App\Models\Rol;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RolRequest extends FormRequest
{
    /**
     * Autorización. La validación de permisos granulares (§5.6) se agregará vía middleware
     * `can:roles.*` cuando se implemente el login (Fase 1, paso 5).
     */
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del rol es obligatorio.',
            'nombre.unique' => 'Ya existe un rol con ese nombre.',
            'nombre.string' => 'El nombre debe ser una cadena de texto.',
            'nombre.max' => 'El nombre no debe exceder los :max caracteres.',
            'descripcion.max' => 'La descripción no debe exceder los :max caracteres.',
            'permisos.array' => 'Los permisos deben enviarse como una lista.',
            'permisos.*.exists' => 'Uno de los permisos seleccionados no existe.',
        ];
    }

    /**
     * Reglas de validación.
     *
     * `permisos` es la lista completa de ids que debe quedar asignada al rol, no un
     * incremento: el controlador hace `sync()`, así que enviar un arreglo vacío deja al rol
     * sin permisos. Es opcional para poder editar solo el nombre sin reenviar el pivote.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rol = $this->route('rol');
        $id = $rol instanceof Rol ? $rol->id : $rol;

        return [
            'nombre' => [
                'required',
                'string',
                'max:80',
                Rule::unique('roles', 'nombre')->ignore($id),
            ],
            'descripcion' => ['nullable', 'string', 'max:255'],
            // `activo` no se acepta aquí: activar y desactivar tienen sus endpoints, que exigen
            // `roles.desactivar` y aplican §5.5. Aceptarlo en la edición dejaba desactivar un rol
            // con usuarios asignados teniendo solo `roles.editar`.
            'permisos' => ['sometimes', 'array'],
            'permisos.*' => [Rule::exists('permisos', 'id')],
        ];
    }
}
