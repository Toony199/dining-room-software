<?php

namespace App\Http\Controllers;

use App\Http\Requests\RolRequest;
use App\Http\Resources\RolResource;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RolesController extends Controller
{
    /**
     * Número de registros por página cuando el cliente no especifica `per_page`.
     */
    private const PER_PAGE = 10;

    /**
     * Lista roles paginados (§5.2).
     *
     * Query params:
     *  - `activo=1|0`  filtra por estado. El formulario que asigna un rol a una cuenta debe
     *                  pasar `activo=1`: un rol desactivado no debe ofrecerse.
     *  - `q=texto`     búsqueda parcial por nombre.
     *  - `per_page=n`  tamaño de página (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $roles = Rol::query()
            ->withCount(['permisos', 'usuarios'])
            ->when($request->filled('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->when($request->filled('q'), fn ($query) => $query->where('nombre', 'like', '%'.$request->string('q').'%'))
            ->orderBy('nombre')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return RolResource::collection($roles);
    }

    /**
     * Crea un rol y le asigna sus permisos (§5.2).
     */
    public function store(RolRequest $request): JsonResponse
    {
        $datos = $request->validated();

        // El rol y su pivote se guardan juntos: un rol a medio configurar es un rol que
        // otorga menos permisos de los que el administrador creyó darle.
        $rol = DB::transaction(function () use ($datos) {
            $rol = Rol::create($datos);
            $rol->permisos()->sync($datos['permisos'] ?? []);

            return $rol;
        });

        return (new RolResource($rol->load('permisos')->loadCount(['permisos', 'usuarios'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Muestra un rol con sus permisos.
     */
    public function show(Rol $rol): RolResource
    {
        return new RolResource($rol->load('permisos')->loadCount(['permisos', 'usuarios']));
    }

    /**
     * Edita un rol y sincroniza sus permisos (§5.2, §5.4).
     *
     * El cambio impacta de inmediato a todos los usuarios que tengan el rol, porque los
     * permisos se consultan contra el pivote en cada petición y nunca se copian a la cuenta.
     */
    public function update(RolRequest $request, Rol $rol): RolResource|JsonResponse
    {
        if ($rol->esProtegido()) {
            return $this->rechazarProtegido($rol, 'editarse');
        }

        $datos = $request->validated();

        DB::transaction(function () use ($rol, $datos) {
            $rol->update($datos);

            // `sometimes` en las reglas: si el cliente no manda `permisos`, se editan solo los
            // datos del rol y el pivote queda intacto. Mandar [] sí lo deja sin permisos.
            if (array_key_exists('permisos', $datos)) {
                $rol->permisos()->sync($datos['permisos']);
            }
        });

        return new RolResource($rol->load('permisos')->loadCount(['permisos', 'usuarios']));
    }

    /**
     * Desactiva un rol (§5.5).
     *
     * Un rol con usuarios asignados no puede desactivarse: hay que reasignarlos primero. Si se
     * permitiera, esas cuentas quedarían sin permisos efectivos de un momento a otro y sin
     * pista visible de por qué.
     */
    public function desactivar(Rol $rol): RolResource|JsonResponse
    {
        if ($rol->esProtegido()) {
            return $this->rechazarProtegido($rol, 'desactivarse');
        }

        $asignados = $rol->usuarios()->count();

        if ($asignados > 0) {
            return response()->json([
                'message' => $asignados === 1
                    ? 'No se puede desactivar el rol: hay 1 usuario asignado. Reasígnalo primero.'
                    : "No se puede desactivar el rol: hay {$asignados} usuarios asignados. Reasígnalos primero.",
                'errors' => ['rol' => ['El rol tiene usuarios asignados.']],
                'usuarios_count' => $asignados,
            ], 422);
        }

        $rol->update(['activo' => false]);

        return new RolResource($rol->loadCount(['permisos', 'usuarios']));
    }

    /**
     * Reactiva un rol previamente desactivado.
     */
    public function activar(Rol $rol): RolResource
    {
        $rol->update(['activo' => true]);

        return new RolResource($rol->loadCount(['permisos', 'usuarios']));
    }

    /**
     * Un rol protegido lo necesita el sistema para funcionar (§5.2).
     *
     * Se comprueba aquí y no solo en la interfaz: deshabilitar un botón no impide que alguien
     * mande el PUT a mano, y lo que está en juego es quedarse sin nadie capaz de administrar la
     * instalación.
     */
    private function rechazarProtegido(Rol $rol, string $accion): JsonResponse
    {
        return response()->json([
            'message' => "El rol {$rol->nombre} está protegido y no puede {$accion}: el sistema lo necesita para conservar el acceso administrativo.",
            'errors' => ['rol' => ['Este rol está protegido.']],
        ], 422);
    }

    /**
     * Acota `per_page` para que un cliente no pueda pedir la tabla completa en una sola llamada.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', self::PER_PAGE), 100));
    }
}
