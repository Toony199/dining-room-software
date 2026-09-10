<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una cuenta de sistema desde el módulo de usuarios (§5).
 *
 * El alta desde el formulario de personas usa las mismas reglas anidadas bajo `cuenta.`
 * (ver PersonasRequest y CuentaSistemaRules).
 */
class UsuarioRequest extends FormRequest
{
    /**
     * Autorización. La validación de permisos granulares (§5.6) se agregará vía middleware
     * `can:usuarios.*` cuando se implemente el login.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CuentaSistemaRules::mensajes() + [
            'persona_id.required' => 'La cuenta debe pertenecer a una persona.',
            'persona_id.exists' => 'La persona seleccionada no existe o está dada de baja.',
            'persona_id.unique' => 'Esa persona ya tiene una cuenta de sistema.',
            'persona_id.in' => 'La cuenta no puede transferirse a otra persona.',
            'rol_id.in' => $this->cuentaEditada()?->esProtegida()
                ? 'El rol de la cuenta administradora del sistema no puede cambiarse.'
                : 'No puedes cambiar tu propio rol: podrías quedarte sin acceso. Pídele a otro administrador que lo haga.',
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $cuenta = $this->route('usuario');
        $cuenta = $cuenta instanceof User ? $cuenta : null;

        // En la edición la contraseña no viaja: tiene su propio endpoint, para que cambiar el
        // correo de alguien no obligue a conocer ni reescribir su contraseña.
        $reglas = CuentaSistemaRules::reglas($cuenta, conPassword: $cuenta === null) + [
            'persona_id' => $this->reglasPersona($cuenta),
        ];

        // Cambiarle el rol a la cuenta administradora de arranque, o a la cuenta propia, es otra
        // forma de quedarse sin acceso: la primera deja la instalación sin quien la administre,
        // la segunda deja fuera a quien hizo el cambio. Se acepta el mismo valor para que el
        // formulario pueda reenviarse completo al corregir el correo.
        if ($cuenta?->esProtegida() || $this->esLaPropia($cuenta)) {
            $reglas['rol_id'] = ['required', 'integer', Rule::in([$cuenta->rol_id])];
        }

        return $reglas;
    }

    private function cuentaEditada(): ?User
    {
        $cuenta = $this->route('usuario');

        return $cuenta instanceof User ? $cuenta : null;
    }

    /**
     * ¿La cuenta que se edita es la de quien hace la petición?
     */
    private function esLaPropia(?User $cuenta): bool
    {
        return $cuenta !== null && $cuenta->is($this->user());
    }

    /**
     * La cuenta pertenece a una persona y esa relación no cambia: transferirla sería mover el
     * acceso de una identidad a otra conservando el historial de la primera.
     *
     * En el alta la persona debe existir, estar ACTIVA (§3.3: una persona inactiva no puede
     * iniciar sesión, así que crearle una cuenta no tendría efecto) y no tener cuenta ya
     * (§3.1: máximo una por persona; el unique de la tabla lo garantiza, esto lo convierte en
     * un 422 explicable en vez de un 500).
     *
     * @return array<int, mixed>
     */
    private function reglasPersona(?User $cuenta): array
    {
        if ($cuenta !== null) {
            return ['required', 'integer', Rule::in([$cuenta->persona_id])];
        }

        return [
            'required',
            'integer',
            Rule::exists('personas', 'id')->where('estado', 'ACTIVO'),
            Rule::unique('users', 'persona_id'),
        ];
    }
}
