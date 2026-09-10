<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Inicio y cierre de sesión de las cuentas de sistema (§3.1).
 *
 * La sesión vive en una cookie httpOnly gestionada por Sanctum en modo SPA (ver
 * bootstrap/app.php), no en un token que el navegador tenga que guardar.
 */
class AuthController extends Controller
{
    /**
     * Relaciones que necesita la sesión: la identidad para mostrarla y el rol con sus permisos
     * para que la UI sepa qué ofrecer.
     *
     * @var array<int, string>
     */
    private const RELACIONES = ['persona', 'rol.permisos'];

    /**
     * Inicia sesión.
     *
     * El orden de las comprobaciones es deliberado:
     *
     * 1. Rate limit, para que probar contraseñas a ciegas no sea gratis.
     * 2. Credenciales. Si fallan, el mensaje es genérico: decir "esa cuenta no existe" o
     *    "esa cuenta está suspendida" a quien no acertó la contraseña regala información
     *    sobre quién trabaja aquí.
     * 3. Estado de la cuenta. Aquí sí se explica el motivo: para llegar a este punto hay que
     *    haber acertado la contraseña, así que no se filtra nada que el solicitante no sepa ya.
     *
     * La sesión solo se abre después de los tres pasos: una persona dada de baja no puede
     * iniciar sesión (§3.3), ni siquiera un instante.
     */
    public function login(LoginRequest $request): UsuarioResource
    {
        $request->asegurarQueNoEsFuerzaBruta();

        $credenciales = $request->only('email', 'password');

        // `validate` comprueba el hash sin abrir sesión, a diferencia de `attempt`.
        if (! Auth::validate($credenciales)) {
            $request->registrarIntentoFallido();

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        $usuario = User::with(self::RELACIONES)
            ->where('email', $credenciales['email'])
            ->firstOrFail();

        if ($motivo = $usuario->motivoBloqueo()) {
            $request->registrarIntentoFallido();

            throw ValidationException::withMessages(['email' => $motivo]);
        }

        $request->limpiarIntentos();

        Auth::login($usuario, $request->boolean('recordarme'));

        // Contra fijación de sesión: el id que traía el visitante antes de autenticarse se
        // descarta, para que un id plantado de antemano no quede asociado a esta cuenta.
        $request->session()->regenerate();

        return new UsuarioResource($usuario);
    }

    /**
     * Cierra la sesión e invalida la cookie.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Cuenta autenticada, con su rol y sus permisos.
     *
     * El frontend la pide al arrancar para saber si hay sesión y qué ofrecer en la interfaz.
     * Responde 401 cuando no hay sesión, que es lo que el router del SPA usa para mandar al
     * login sin tener que adivinar.
     */
    public function me(Request $request): UsuarioResource
    {
        return new UsuarioResource($request->user()->load(self::RELACIONES));
    }
}
