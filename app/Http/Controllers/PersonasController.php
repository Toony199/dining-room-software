<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonasRequest;
use App\Http\Resources\PersonasResource;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PersonasController extends Controller
{
    /**
     * Número de registros por página cuando el cliente no especifica `per_page`.
     */
    private const PER_PAGE = 10;

    /**
     * Lista personas paginadas (§3).
     *
     * Query params:
     *  - `q=texto`            búsqueda parcial por nombre, apellidos o número de empleado.
     *  - `estado=ACTIVO|INACTIVO`  filtra por estado. Administración ve ambos por defecto; los
     *                         flujos operativos (fichas, gafetes) deben pasar `estado=ACTIVO`,
     *                         porque una persona inactiva no opera (§3.3).
     *  - `departamento_id=n`  personas adscritas a un departamento (§3.4).
     *  - `sin_cuenta=1`       solo quienes aún no tienen cuenta de sistema. Lo usa el
     *                         formulario de alta de cuentas: ofrecer a alguien que ya tiene
     *                         una solo produciría un 422 (§3.1, máximo una por persona).
     *  - `per_page=n`         tamaño de página (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $personas = Persona::query()
            ->with(['departamento', 'cuenta.rol'])
            ->when($request->filled('estado'), fn ($query) => $query->where('estado', (string) $request->string('estado')->upper()))
            ->when($request->filled('departamento_id'), fn ($query) => $query->where('departamento_id', $request->integer('departamento_id')))
            ->when($request->boolean('sin_cuenta'), fn ($query) => $query->whereDoesntHave('cuenta'))
            ->when($request->filled('q'), fn ($query) => $query->where($this->busqueda((string) $request->string('q'))))
            ->orderBy('primer_apellido')
            ->orderBy('segundo_apellido')
            ->orderBy('nombre')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PersonasResource::collection($personas);
    }

    /**
     * Alta de una persona (§3.1). Nace ACTIVA por el default del modelo.
     *
     * Si el formulario marcó que la persona tendrá acceso administrativo, el bloque `cuenta`
     * trae sus credenciales y su rol, y ambos registros se crean en una transacción: una
     * persona a la que le falló la cuenta y quedó guardada obligaría al capturista a adivinar
     * si tiene que reintentar el alta completa o solo la cuenta.
     */
    public function store(PersonasRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $cuenta = $datos['cuenta'] ?? null;
        unset($datos['cuenta']);

        // Crear la cuenta junto con la persona sigue siendo crear una cuenta. Sin esto, quien
        // solo puede dar de alta personal podría fabricarse una con rol de acceso total y
        // saltarse todo el modelo de permisos (§5.6).
        if ($cuenta !== null) {
            Gate::authorize('usuarios.crear');
        }

        $persona = DB::transaction(function () use ($datos, $cuenta) {
            $persona = Persona::create($datos);

            if ($cuenta !== null) {
                // El hash lo hace el cast `password => hashed` del modelo User.
                $persona->cuenta()->create($cuenta);
            }

            return $persona;
        });

        return (new PersonasResource($persona->load(['departamento', 'cuenta.rol'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Muestra una persona.
     */
    public function show(Persona $persona): PersonasResource
    {
        return new PersonasResource($persona->load(['departamento', 'cuenta.rol']));
    }

    /**
     * Edita una persona, incluido el cambio de departamento (§3.4). El número de empleado es
     * inmutable y PersonasRequest lo rechaza si viene distinto (§3.2).
     */
    public function update(PersonasRequest $request, Persona $persona): PersonasResource
    {
        $persona->update($request->validated());

        return new PersonasResource($persona->load(['departamento', 'cuenta.rol']));
    }

    /**
     * Baja lógica de la persona (§3.3). Nunca se elimina físicamente: su número de empleado queda
     * reservado para siempre y su historial debe seguir disponible para auditoría.
     */
    public function desactivar(Persona $persona): PersonasResource|JsonResponse
    {
        // La baja de la persona corta el acceso de su cuenta (§3.3). Si es la cuenta
        // administradora de arranque, la instalación se quedaría sin quien la administre.
        if ($persona->cuenta?->esProtegida()) {
            return response()->json([
                'message' => 'Esta persona tiene la cuenta administradora del sistema y no puede darse de baja: se perdería el acceso para administrar la instalación.',
                'errors' => ['persona' => ['La persona tiene la cuenta administradora del sistema.']],
            ], 422);
        }

        $persona->update(['estado' => 'INACTIVO']);

        return new PersonasResource($persona->load(['departamento', 'cuenta.rol']));
    }

    /**
     * Reingreso: reactiva una persona dada de baja.
     */
    public function activar(Persona $persona): PersonasResource
    {
        $persona->update(['estado' => 'ACTIVO']);

        return new PersonasResource($persona->load(['departamento', 'cuenta.rol']));
    }

    /**
     * Filtro de texto libre. Va agrupado en un callback para que los `orWhere` no se mezclen con
     * los filtros de estado y departamento (si no, `estado=ACTIVO` dejaría de aplicar al buscar).
     */
    private function busqueda(string $termino): callable
    {
        $like = '%'.$termino.'%';

        return fn (Builder $query) => $query
            ->where('nombre', 'like', $like)
            ->orWhere('primer_apellido', 'like', $like)
            ->orWhere('segundo_apellido', 'like', $like)
            ->orWhere('numero_empleado', 'like', $like);
    }

    /**
     * Acota `per_page` para que un cliente no pueda pedir la tabla completa en una sola llamada.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', self::PER_PAGE), 100));
    }
}
