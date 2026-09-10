<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Password;

/**
 * Reglas y mensajes de la cuenta de sistema (§3.1).
 *
 * Viven aquí y no dentro de un FormRequest porque la cuenta se captura desde dos lugares
 * distintos: anidada bajo `cuenta.` en el alta de una persona (§3.1: "durante el alta se
 * deberá determinar si la persona tendrá acceso administrativo") y suelta en el módulo de
 * usuarios (§5). Las dos entradas tienen que validar exactamente lo mismo, o la más laxa se
 * convierte en el hueco por donde se cuelan cuentas mal formadas.
 */
class CuentaSistemaRules
{
    /**
     * Reglas de las credenciales y el rol.
     *
     * @param  User|null  $cuenta  cuenta existente en una edición; null en un alta.
     * @param  string  $prefijo  '' cuando los campos van en la raíz, 'cuenta.' cuando van anidados.
     * @param  bool  $conPassword  el cambio de contraseña tiene su propio endpoint, así que la
     *                             edición de la cuenta no la incluye.
     * @return array<string, array<int, mixed>>
     */
    public static function reglas(?User $cuenta = null, string $prefijo = '', bool $conPassword = true): array
    {
        $reglas = [
            $prefijo.'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($cuenta?->id),
            ],
            // §5.1: la cuenta tiene exactamente un rol. La columna es nullable solo por el
            // orden en que corrieron las migraciones; a nivel de aplicación es obligatorio.
            $prefijo.'rol_id' => ['required', 'integer', self::reglaRol($cuenta)],
        ];

        if ($conPassword) {
            $reglas[$prefijo.'password'] = ['required', 'confirmed', Password::min(8)];
        }

        return $reglas;
    }

    /**
     * El rol debe existir y estar activo: un rol dado de baja no debe ofrecerse para cuentas
     * nuevas. Excepción: al editar una cuenta se admite el rol que ya tiene aunque lo hayan
     * desactivado después, para no bloquear la edición de su correo.
     */
    private static function reglaRol(?User $cuenta): Exists
    {
        $regla = Rule::exists('roles', 'id');

        if ($cuenta === null) {
            $regla->where('activo', true);
        }

        return $regla;
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(string $prefijo = ''): array
    {
        return [
            $prefijo.'email.required' => 'El correo de acceso es obligatorio.',
            $prefijo.'email.email' => 'El correo de acceso no tiene un formato válido.',
            $prefijo.'email.unique' => 'Ya existe una cuenta con ese correo.',
            $prefijo.'email.max' => 'El correo no debe exceder los :max caracteres.',
            $prefijo.'password.required' => 'La contraseña es obligatoria.',
            $prefijo.'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            $prefijo.'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            $prefijo.'rol_id.required' => 'La cuenta debe tener un rol asignado.',
            $prefijo.'rol_id.exists' => 'El rol seleccionado no existe o está desactivado.',
        ];
    }
}
