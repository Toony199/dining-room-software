<?php

namespace App\Http\Controllers;

use App\Http\Requests\PagoRequest;
use App\Http\Resources\PagoResource;
use App\Models\Ficha;
use App\Models\Pago;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Cobro físico de las fichas (§12, §14).
 *
 * El sistema no procesa pagos: el dinero se recibe en la caja y aquí solo se registra que se
 * recibió (§2.1). Lo que sí hace es validar el monto como un POS y, al confirmar, crear los
 * derechos de consumo (§2.2).
 */
class PagosController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Pagos confirmados, del más reciente al más antiguo, con el corte por cobrador.
     *
     * Query params:
     *  - `desde=AAAA-MM-DD`, `hasta=AAAA-MM-DD`  por omisión, hoy: es lo que se mira al cerrar caja.
     *  - `cobrador_id=n`
     *  - `periodo_id=n`
     *  - `per_page=n` (1..100).
     */
    public function index(Request $request): JsonResponse
    {
        $desde = Carbon::parse($request->date('desde') ?? today())->startOfDay();
        $hasta = Carbon::parse($request->date('hasta') ?? $desde)->endOfDay();

        $pagos = Pago::query()
            ->with(['cobrador.persona', 'ficha.persona', 'ficha.dias.diaPeriodo'])
            ->whereBetween('confirmado_en', [$desde, $hasta])
            ->when($request->filled('cobrador_id'), fn (Builder $query) => $query->where('cobrador_id', $request->integer('cobrador_id')))
            ->when($request->filled('periodo_id'), fn (Builder $query) => $query->whereHas('ficha', fn (Builder $ficha) => $ficha->where('periodo_id', $request->integer('periodo_id'))))
            ->orderByDesc('confirmado_en')
            ->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', self::PER_PAGE), 100)))
            ->withQueryString();

        return PagoResource::collection($pagos)
            ->additional(['meta' => ['corte' => $this->corte($desde, $hasta, $request)]])
            ->response();
    }

    /**
     * Confirma el cobro de una ficha (§12.1) y crea sus derechos de consumo.
     */
    public function store(PagoRequest $request, Ficha $ficha): JsonResponse
    {
        try {
            $pago = $ficha->confirmarPago((float) $request->validated('monto_recibido'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['monto_recibido' => [$e->getMessage()]],
            ], 422);
        }

        return (new PagoResource($pago->load(['cobrador.persona', 'ficha.persona', 'ficha.dias.diaPeriodo'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Lo cobrado en el rango, por cobrador y en total: es lo que se compara contra el efectivo al
     * cerrar la caja. Se suma `total_cobrado`, no lo recibido: la diferencia se devolvió como
     * cambio y nunca fue de la empresa.
     *
     * @return array<string, mixed>
     */
    private function corte(Carbon $desde, Carbon $hasta, Request $request): array
    {
        $porCobrador = Pago::query()
            ->with('cobrador.persona')
            ->whereBetween('confirmado_en', [$desde, $hasta])
            ->when($request->filled('cobrador_id'), fn (Builder $query) => $query->where('cobrador_id', $request->integer('cobrador_id')))
            ->selectRaw('cobrador_id, count(*) as fichas, sum(total_cobrado) as total')
            ->groupBy('cobrador_id')
            ->get();

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'fichas' => (int) $porCobrador->sum('fichas'),
            'total' => number_format((float) $porCobrador->sum('total'), 2, '.', ''),
            'por_cobrador' => $porCobrador->map(fn (Pago $fila) => [
                'cobrador_id' => $fila->cobrador_id,
                'cobrador' => $fila->cobrador?->persona?->nombre_completo ?? $fila->cobrador?->email,
                'fichas' => (int) $fila->fichas,
                'total' => number_format((float) $fila->total, 2, '.', ''),
            ])->all(),
        ];
    }
}
