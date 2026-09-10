<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Intentos permitidos antes de bloquear temporalmente la combinación correo + IP.
     */
    private const INTENTOS = 5;

    /**
     * Segundos que dura el bloqueo.
     */
    private const ESPERA = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no tiene un formato válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'recordarme' => ['boolean'],
        ];
    }

    /**
     * Corta los intentos por fuerza bruta.
     *
     * La llave combina correo e IP: por correo sola, cualquiera podría dejar fuera a un
     * compañero repitiendo contraseñas malas contra su cuenta; por IP sola, una oficina
     * entera detrás de un mismo NAT se bloquearía junta.
     */
    public function asegurarQueNoEsFuerzaBruta(): void
    {
        if (! RateLimiter::tooManyAttempts($this->llaveDeIntentos(), self::INTENTOS)) {
            return;
        }

        $segundos = RateLimiter::availableIn($this->llaveDeIntentos());

        throw ValidationException::withMessages([
            'email' => "Demasiados intentos fallidos. Vuelve a intentarlo en {$segundos} segundos.",
        ]);
    }

    public function registrarIntentoFallido(): void
    {
        RateLimiter::hit($this->llaveDeIntentos(), self::ESPERA);
    }

    public function limpiarIntentos(): void
    {
        RateLimiter::clear($this->llaveDeIntentos());
    }

    private function llaveDeIntentos(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')).'|'.$this->ip());
    }
}
