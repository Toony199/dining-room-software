<?php

namespace App\Http\Controllers;

use App\Http\Requests\TarifaRequest;
use App\Http\Resources\TarifaResource;
use App\Models\Tarifa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catálogo de precios por día (§6.4, §18).
 *
 * Solo consultar y registrar: una tarifa no se edita ni se da de baja, porque es el respaldo de lo
 * que ya se cobró (§6.5). Cambiar el precio es registrar otro, que puede entrar hoy o quedar
 * programado.
 */
class TarifasController extends Controller
{
    private const PER_PAGE = 10;

    /**
     * Historial de precios, del más reciente al más antiguo.
     *
     * Query params:
     *  - `per_page=n`  tamaño de página (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tarifas = Tarifa::query()
            ->with('creadoPor.persona')
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', self::PER_PAGE), 100)))
            ->withQueryString();

        return TarifaResource::collection($tarifas);
    }

    /**
     * El precio que rige hoy, que es el que se congelará en los días de los periodos que se creen
     * (§6.5). `data` viene en null mientras no se haya registrado ninguno.
     */
    public function vigente(): JsonResponse
    {
        $tarifa = Tarifa::vigente()?->load('creadoPor.persona');

        return response()->json([
            'data' => $tarifa ? new TarifaResource($tarifa) : null,
        ]);
    }

    /**
     * Registra un precio nuevo y cierra el anterior el día previo.
     */
    public function store(TarifaRequest $request): JsonResponse
    {
        $tarifa = Tarifa::registrar(
            (float) $request->validated('precio'),
            $request->validated('vigente_desde'),
            $request->user()->getKey(),
        );

        return (new TarifaResource($tarifa->load('creadoPor.persona')))
            ->response()
            ->setStatusCode(201);
    }
}
