<?php

namespace App\Http\Requests;

use App\Models\Persona;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class PersonasRequest extends FormRequest
{
    /**
     * Autorización. La validación de permisos granulares (§5.6) se agregará vía middleware
     * `can:personas.*` cuando se implemente el módulo de auth (Fase 1, paso 4).
     */
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            // Número de empleado (§3.2)
            'numero_empleado.required' => 'El numero de empleado es obligatorio.',
            'numero_empleado.unique' => 'Este numero de empleado ya existe.',
            'numero_empleado.string' => 'El numero de empleado debe ser una cadena de texto.',
            'numero_empleado.max' => 'El numero de empleado debe ser maximo :max caracteres.',
            'numero_empleado.in' => 'El numero de empleado no puede modificarse.',
            // Nombre
            'nombre.required' => 'El campo nombre es obligatorio.',
            'nombre.string' => 'El nombre debe ser una cadena de texto.',
            'nombre.max' => 'El nombre debe de ser maximo :max caracteres.',
            // Apellidos
            'primer_apellido.required' => 'El campo apellido es obligatorio.',
            'primer_apellido.max' => 'El campo apellido no debe exceder los :max caracteres.',
            'primer_apellido.string' => 'El campo apellido debe ser una cadena de texto.',
            'segundo_apellido.max' => 'El apellido debe de ser menor a :max.',
            'segundo_apellido.string' => 'El campo apellido debe de ser una cadena de texto.',
            // Departamento (§3.4)
            'departamento_id.required' => 'El campo departamento es obligatorio.',
            'departamento_id.exists' => 'El departamento seleccionado no existe o está dado de baja.',
        ] + CuentaSistemaRules::mensajes('cuenta.');
    }

    /**
     * Reglas de validación.
     *
     * `estado` no se valida aquí a propósito: el alta siempre nace ACTIVO (default del modelo) y
     * el cambio de estado va por los endpoints `desactivar`/`activar` (§3.3), no por el formulario.
     * `foto_path` tampoco: se escribirá desde el endpoint de carga de fotografía (§3.1), no
     * aceptando una ruta arbitraria del cliente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $persona = $this->route('persona');
        $persona = $persona instanceof Persona ? $persona : null;

        $reglas = [
            'numero_empleado' => $this->reglasNumeroEmpleado($persona),
            'nombre' => ['required', 'string', 'max:30'],
            'primer_apellido' => ['required', 'string', 'max:15'],
            // La columna es nullable: hay personas con un solo apellido.
            'segundo_apellido' => ['nullable', 'string', 'max:15'],
            'departamento_id' => ['required', 'integer', $this->reglaDepartamento($persona)],
            'cuenta' => ['sometimes', 'nullable', 'array'],
        ];

        // §3.1: "durante el alta se deberá determinar si la persona tendrá acceso
        // administrativo". El bloque `cuenta` solo viaja cuando el formulario marcó que sí;
        // si viene, se valida con las mismas reglas que el módulo de usuarios.
        //
        // Solo en el alta: cambiar el correo, el rol o la contraseña de una cuenta existente
        // va por /api/usuarios, para no mezclar la identidad de la persona con su acceso.
        if ($persona === null && $this->filled('cuenta')) {
            $reglas += CuentaSistemaRules::reglas(prefijo: 'cuenta.');
        }

        return $reglas;
    }

    /**
     * El número de empleado es un identificador empresarial permanente e inmutable (§3.2):
     * en el alta debe ser único, y en la edición solo se acepta el valor que ya tiene la persona
     * —así el frontend puede reenviar el formulario completo sin que el campo cambie en silencio—.
     *
     * @return array<int, mixed>
     */
    private function reglasNumeroEmpleado(?Persona $persona): array
    {
        if ($persona !== null) {
            return ['required', 'string', Rule::in([$persona->numero_empleado])];
        }

        return ['required', 'string', 'max:30', Rule::unique('personas', 'numero_empleado')];
    }

    /**
     * El departamento debe existir y estar activo: los formularios no deben proponer catálogos
     * dados de baja (§3.4). Excepción: al editar una persona se admite el departamento que ya
     * tiene aunque haya sido desactivado después, para no bloquear la edición de sus otros datos.
     */
    private function reglaDepartamento(?Persona $persona): Exists
    {
        $regla = Rule::exists('departamentos', 'id');

        $conservaSuDepartamento = $persona !== null
            && (int) $this->input('departamento_id') === (int) $persona->departamento_id;

        if (! $conservaSuDepartamento) {
            $regla->where('activo', true);
        }

        return $regla;
    }
}
