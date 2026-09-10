<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordRequest;
use App\Http\Requests\UsuarioRequest;
use App\Http\Resources\PersonaDisponibleResource;
use App\Http\Resources\RolAsignableResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Cuentas de sistema (§3.1, §5).
 *
 * No hay DELETE: una cuenta se suspende, igual que una persona se da de baja (§3.3). Borrarla
 * dejaría sin autor las operaciones que registró.
 */
class UsuariosController extends Controller
{
    /**
     * Número de registros por página cuando el cliente no especifica `per_page`.
     */
    private const PER_PAGE = 10;

    /**
     * Lista cuentas paginadas.
     *
     * Query params:
     *  - `q=texto`     busca por correo, nombre, apellidos o número de empleado de la persona.
     *  - `activo=1|0`  filtra por estado de la cuenta.
     *  - `rol_id=n`    cuentas con un rol determinado (útil para reasignar antes de
     *                  desactivar un rol, §5.5).
     *  - `per_page=n`  tamaño de página (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $usuarios = User::query()
            ->with(['persona', 'rol'])
            ->when($request->filled('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->when($request->filled('rol_id'), fn ($query) => $query->where('rol_id', $request->integer('rol_id')))
            ->when($request->filled('q'), fn ($query) => $query->where($this->busqueda((string) $request->string('q'))))
            ->orderBy('email')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return UsuarioResource::collection($usuarios);
    }

    /**
     * Crea la cuenta de sistema de una persona existente (§3.1).
     *
     * El hash de la contraseña lo hace el cast `password => hashed` del modelo, así que aquí
     * nunca se toca el valor en claro.
     */
    public function store(UsuarioRequest $request): JsonResponse
    {
        $usuario = User::create($request->validated());

        return (new UsuarioResource($usuario->load(['persona', 'rol'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Muestra una cuenta.
     */
    public function show(User $usuario): UsuarioResource
    {
        return new UsuarioResource($usuario->load(['persona', 'rol']));
    }

    /**
     * Edita el correo y el rol de la cuenta (§5.1: sigue siendo exactamente un rol).
     *
     * La persona dueña de la cuenta es inmutable y la contraseña va por su propio endpoint;
     * UsuarioRequest se encarga de ambas cosas.
     */
    public function update(UsuarioRequest $request, User $usuario): UsuarioResource
    {
        $usuario->update($request->validated());

        return new UsuarioResource($usuario->load(['persona', 'rol']));
    }

    /**
     * Restablece la contraseña de la cuenta.
     */
    public function password(PasswordRequest $request, User $usuario): UsuarioResource
    {
        $usuario->update(['password' => $request->validated()['password']]);

        return new UsuarioResource($usuario->load(['persona', 'rol']));
    }

    /**
     * Suspende el acceso sin dar de baja a la persona.
     *
     * Es un interruptor independiente del estado de la persona: sirve para cortar el acceso
     * de alguien que sigue trabajando y usando el comedor (§3.1 vs §3.3).
     */
    public function suspender(User $usuario): UsuarioResource|JsonResponse
    {
        if ($usuario->esProtegida()) {
            return response()->json([
                'message' => 'La cuenta administradora del sistema no puede suspenderse: se perdería el acceso para administrar la instalación.',
                'errors' => ['usuario' => ['Esta cuenta está protegida.']],
            ], 422);
        }

        $usuario->update(['activo' => false]);

        return new UsuarioResource($usuario->load(['persona', 'rol']));
    }

    /**
     * Reactiva una cuenta suspendida.
     *
     * Reactivar la cuenta no basta para que el usuario entre: si su persona está de baja o su
     * rol desactivado, `puedeOperar()` sigue devolviendo false (§3.3). Por eso el recurso
     * expone `puede_operar` además de `activo`.
     */
    public function reactivar(User $usuario): UsuarioResource
    {
        $usuario->update(['activo' => true]);

        return new UsuarioResource($usuario->load(['persona', 'rol']));
    }

    /**
     * Roles que pueden asignarse a una cuenta: solo los activos, y solo `id` y `nombre`.
     *
     * Existe para que administrar cuentas no obligue a tener `roles.ver`. Sin este catálogo, un
     * rol con `usuarios.crear` pero sin `roles.ver` veía vacío el select de rol y no podía crear
     * ninguna cuenta, aunque su rol decía que sí (§5.1 exige un rol en cada cuenta).
     *
     * Lo puede leer cualquiera con un permiso `usuarios.*`: además del alta y la edición, el
     * listado lo usa para su filtro por rol, y el nombre del rol ya aparece en cada fila.
     */
    public function rolesAsignables(): AnonymousResourceCollection
    {
        // `can:` solo admite una clave; aquí basta cualquiera de las tres.
        if (! Gate::any(['usuarios.ver', 'usuarios.crear', 'usuarios.editar'])) {
            throw new AuthorizationException;
        }

        $roles = Rol::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return RolAsignableResource::collection($roles);
    }

    /**
     * Personas que pueden recibir una cuenta: activas (§3.3) y sin cuenta todavía (§3.1).
     *
     * Existe por la misma razón que `rolesAsignables`: crear cuentas no debería exigir
     * `colaboradores.ver`, ni entregar el expediente completo de cada persona para elegir una.
     */
    public function personasDisponibles(Request $request): AnonymousResourceCollection
    {
        $personas = Persona::query()
            ->where('estado', 'ACTIVO')
            ->whereDoesntHave('cuenta')
            ->orderBy('primer_apellido')
            ->orderBy('segundo_apellido')
            ->orderBy('nombre')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PersonaDisponibleResource::collection($personas);
    }

    /**
     * Filtro de texto libre. El correo vive en `users` y el resto de los datos en `personas`,
     * así que la búsqueda cruza la relación. Va agrupado en un callback para que los `orWhere`
     * no se mezclen con los filtros de estado y rol.
     */
    private function busqueda(string $termino): callable
    {
        $like = '%'.$termino.'%';

        return fn (Builder $query) => $query
            ->where('email', 'like', $like)
            ->orWhereHas('persona', fn (Builder $persona) => $persona
                ->where('nombre', 'like', $like)
                ->orWhere('primer_apellido', 'like', $like)
                ->orWhere('segundo_apellido', 'like', $like)
                ->orWhere('numero_empleado', 'like', $like)
            );
    }

    /**
     * Acota `per_page` para que un cliente no pueda pedir la tabla completa en una sola llamada.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', self::PER_PAGE), 100));
    }
}
