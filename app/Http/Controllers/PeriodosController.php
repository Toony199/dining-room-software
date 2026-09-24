<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiaPeriodoRequest;
use App\Http\Requests\PeriodoRequest;
use App\Http\Resources\PeriodoResource;
use App\Models\DiaPeriodo;
use App\Models\Periodo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Periodos semanales de servicio (§6, §8, §9).
 *
 * Sin DELETE: un periodo guarda el precio con el que se cobró cada día, así que se conserva. Las
 * transiciones de estado son las de §8 y cada una tiene su permiso (§9).
 */
class PeriodosController extends Controller
{
    private const PER_PAGE = 10;

    /**
     * Lista periodos, del más reciente al más antiguo.
     *
     * Query params:
     *  - `estado=BORRADOR|ABIERTO|PAGO_CERRADO|CONSOLIDADO`
     *  - `per_page=n`  tamaño de página (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $periodos = Periodo::query()
            ->withCount(['dias as dias_disponibles_count' => fn (Builder $query) => $query->where('disponible', true)])
            ->when($request->filled('estado'), fn (Builder $query) => $query->where('estado', (string) $request->string('estado')->upper()))
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', self::PER_PAGE), 100)))
            ->withQueryString();

        return PeriodoResource::collection($periodos);
    }

    /**
     * Crea un periodo con las fechas que decida el gestor.
     */
    public function store(PeriodoRequest $request): JsonResponse
    {
        $datos = $request->validated();

        try {
            $periodo = Periodo::crear(
                $datos['fecha_inicio'],
                $datos['fecha_fin'],
                $datos['ventana_inicio'],
                $datos['ventana_fin'],
            );
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage(), 'periodo');
        }

        return (new PeriodoResource($periodo->load('dias')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Crea el periodo de la semana siguiente con la ventana de siempre (§6.1).
     *
     * Es el mismo trabajo que hace el comando de los martes, y es idempotente: si ya existe,
     * devuelve el que hay con 200 en lugar de duplicarlo.
     */
    public function generarSiguiente(): JsonResponse
    {
        $lunes = Periodo::lunesDeLaSemanaSiguiente();
        $existente = Periodo::query()->whereDate('fecha_inicio', $lunes)->first();

        if ($existente) {
            return (new PeriodoResource($existente->load('dias')))->response()->setStatusCode(200);
        }

        try {
            $periodo = Periodo::generarSemana($lunes);
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage(), 'periodo');
        }

        return (new PeriodoResource($periodo->load('dias')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Periodo $periodo): PeriodoResource
    {
        return new PeriodoResource($periodo->load('dias'));
    }

    /**
     * Ajusta la ventana de generación y pago. Solo en BORRADOR (§8.1): moverla con el periodo ya
     * abierto cambiaría las reglas a mitad del juego para quien ya generó su ficha.
     */
    public function update(PeriodoRequest $request, Periodo $periodo): PeriodoResource|JsonResponse
    {
        if (! $periodo->estaEnBorrador()) {
            return $this->rechazar('Solo se puede configurar un periodo en borrador.', 'periodo');
        }

        $periodo->update($request->validated());

        return new PeriodoResource($periodo->load('dias'));
    }

    /**
     * Marca un día como festivo o fuera de servicio, con su motivo, o ajusta su precio (§6.3, §8.1).
     */
    public function actualizarDia(DiaPeriodoRequest $request, Periodo $periodo, DiaPeriodo $dia): PeriodoResource|JsonResponse
    {
        abort_if($dia->periodo_id !== $periodo->id, 404);

        if (! $periodo->estaEnBorrador()) {
            return $this->rechazar('Solo se pueden cambiar los días de un periodo en borrador.', 'dia');
        }

        $dia->update($request->validated());

        return new PeriodoResource($periodo->load('dias'));
    }

    /**
     * BORRADOR → ABIERTO (§8.2).
     */
    public function abrir(Periodo $periodo): PeriodoResource|JsonResponse
    {
        return $this->transicion($periodo, fn () => $periodo->abrir());
    }

    /**
     * ABIERTO → PAGO_CERRADO (§8.3). Lo hace una persona: después se genera el reporte.
     */
    public function cerrar(Periodo $periodo): PeriodoResource|JsonResponse
    {
        return $this->transicion($periodo, fn () => $periodo->cerrar());
    }

    /**
     * PAGO_CERRADO → ABIERTO (§9). No revive las fichas ya vencidas.
     */
    public function reabrir(Periodo $periodo): PeriodoResource|JsonResponse
    {
        return $this->transicion($periodo, fn () => $periodo->reabrir());
    }

    private function transicion(Periodo $periodo, callable $accion): PeriodoResource|JsonResponse
    {
        try {
            $accion();
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage(), 'estado');
        }

        return new PeriodoResource($periodo->load('dias'));
    }

    private function rechazar(string $mensaje, string $campo): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => [$campo => [$mensaje]],
        ], 422);
    }
}
