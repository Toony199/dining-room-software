<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartamentoRequest;
use App\Http\Resources\DepartamentoResource;
use App\Models\Departamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DepartamentosController extends Controller
{
    /**
     * Número de registros por página cuando el cliente no especifica `per_page`.
     */
    private const PER_PAGE = 10;

    /**
     * Lista departamentos paginados.
     *
     * Query params:
     *  - `activo=1|0`  filtra por estado. Los formularios que ofrecen un departamento a elegir
     *                  deben pasar `activo=1` para no proponer catálogos dados de baja (§3.4).
     *  - `q=texto`     búsqueda parcial por nombre.
     *  - `per_page=n`  tamaño de página (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $departamentos = Departamento::query()
            ->when($request->filled('activo'), fn ($query) => $query->where('activo', $request->boolean('activo')))
            ->when($request->filled('q'), fn ($query) => $query->where('nombre', 'like', '%'.$request->string('q').'%'))
            ->orderBy('nombre')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return DepartamentoResource::collection($departamentos);
    }

    /**
     * Crea un departamento (§3.4).
     */
    public function store(DepartamentoRequest $request): JsonResponse
    {
        $departamento = Departamento::create($request->validated());

        return (new DepartamentoResource($departamento))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Muestra un departamento.
     */
    public function show(Departamento $departamento): DepartamentoResource
    {
        return new DepartamentoResource($departamento);
    }

    /**
     * Edita un departamento (§3.4).
     */
    public function update(DepartamentoRequest $request, Departamento $departamento): DepartamentoResource
    {
        $datos = $request->validated();

        // El formulario puede cambiar el estado, pero hacerlo es activar o desactivar, que tienen
        // su propio permiso. Sin esto, `departamentos.editar` bastaba para dar de baja (§3.4).
        // Reenviar el mismo estado al corregir el nombre no lo exige.
        if (array_key_exists('activo', $datos) && (bool) $datos['activo'] !== $departamento->activo) {
            Gate::authorize('departamentos.desactivar');
        }

        $departamento->update($datos);

        return new DepartamentoResource($departamento);
    }

    /**
     * Baja lógica del departamento (§3.4, RN-07). No se elimina físicamente para conservar el
     * historial de colaboradores y operaciones asociadas.
     */
    public function desactivar(Departamento $departamento): DepartamentoResource
    {
        $departamento->update(['activo' => false]);

        return new DepartamentoResource($departamento);
    }

    /**
     * Reactiva un departamento previamente desactivado.
     */
    public function activar(Departamento $departamento): DepartamentoResource
    {
        $departamento->update(['activo' => true]);

        return new DepartamentoResource($departamento);
    }

    /**
     * Acota `per_page` para que un cliente no pueda pedir la tabla completa en una sola llamada.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', self::PER_PAGE), 100));
    }
}
