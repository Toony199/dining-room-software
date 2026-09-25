<?php

namespace App\Http\Controllers;

use App\Http\Requests\FichaDiasRequest;
use App\Http\Resources\FichaResource;
use App\Models\Ficha;
use App\Models\Gafete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Fichas vistas desde la caja (§12, §13).
 *
 * El cobro gira en torno al folio: la persona llega con él y el cobrador lo escanea o lo teclea.
 * El listado existe para lo otro que hace falta en caja: ver quién falta por pagar antes de que
 * cierre la semana.
 *
 * Sin alta ni baja: las fichas nacen en el kiosco y no se borran; lo único que cambia antes de
 * pagar son sus días (§13).
 */
class FichasController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * Lista fichas, de la más reciente a la más antigua.
     *
     * Query params:
     *  - `estado=PENDIENTE|PAGADA|VENCIDA`
     *  - `periodo_id=n`
     *  - `q=texto`   folio, nombre o número de empleado.
     *  - `per_page=n` (1..100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $fichas = Ficha::query()
            ->with(['persona', 'periodo', 'dias.diaPeriodo', 'pago.cobrador.persona'])
            ->when($request->filled('estado'), fn (Builder $query) => $query->where('estado', (string) $request->string('estado')->upper()))
            ->when($request->filled('periodo_id'), fn (Builder $query) => $query->where('periodo_id', $request->integer('periodo_id')))
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $texto = '%'.$request->string('q').'%';

                $query->where(fn (Builder $sub) => $sub
                    ->where('folio', 'like', $texto)
                    ->orWhereHas('persona', fn (Builder $persona) => $persona
                        ->where('numero_empleado', 'like', $texto)
                        ->orWhere('nombre', 'like', $texto)
                        ->orWhere('primer_apellido', 'like', $texto)
                        ->orWhere('segundo_apellido', 'like', $texto)));
            })
            ->orderByDesc('generada_en')
            ->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', self::PER_PAGE), 100)))
            ->withQueryString();

        return FichaResource::collection($fichas);
    }

    /**
     * La ficha de quien escaneó su gafete en la caja.
     *
     * Hace falta porque el ticket casi nunca se imprime (§14): la persona llega con su gafete, no
     * con el folio. Devuelve la que está por pagar y, si ya pagó, la pagada, para que el cobrador
     * pueda decírselo o reimprimirle el comprobante.
     */
    public function porGafete(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $request->validate(
            ['qr_token' => ['required', 'string', 'max:40']],
            ['qr_token.required' => 'Escanea el gafete de la persona.'],
        );

        $gafete = Gafete::query()->where('qr_token', $request->string('qr_token'))->with('persona')->first();

        if (! $gafete || ! $gafete->estaActivo()) {
            return $this->rechazar($gafete
                ? 'Ese gafete fue reemplazado. Pide el que se le entregó más reciente.'
                : 'No reconocemos ese gafete.');
        }

        // Pueden ser varias: la regla de una sola ficha es por semana (§17.1), así que quien no
        // pagó una semana y pidió la siguiente tiene dos pendientes. Se devuelven todas, y de la
        // más vieja a la más nueva, que es el orden en que conviene cobrarlas.
        $fichas = Ficha::query()
            ->where('persona_id', $gafete->persona_id)
            ->valida()
            // Lo pendiente primero: lo pagado solo se consulta o se reimprime.
            ->orderByRaw('field(estado, ?, ?)', [Ficha::PENDIENTE, Ficha::PAGADA])
            ->orderBy('generada_en')
            ->with(['persona', 'periodo.dias', 'dias.diaPeriodo', 'pago.cobrador.persona'])
            ->get();

        if ($fichas->isEmpty()) {
            return $this->rechazar(sprintf(
                '%s no tiene ninguna ficha por pagar. Primero debe generarla en el kiosco.',
                $gafete->persona->nombre_gafete,
            ));
        }

        return FichaResource::collection($fichas);
    }

    /**
     * La ficha que trae la persona, buscada por su folio: es con lo que llega a la caja (§12).
     */
    public function show(Ficha $ficha): FichaResource
    {
        return new FichaResource($ficha->load(['persona', 'periodo.dias', 'dias.diaPeriodo', 'pago.cobrador.persona']));
    }

    /**
     * Cambia los días antes de cobrar (§13): la persona pide agregar o quitar, y el total se
     * recalcula. Después de pagar ya no se toca (§17.3).
     */
    public function actualizarDias(FichaDiasRequest $request, Ficha $ficha): FichaResource|JsonResponse
    {
        try {
            $ficha->cambiarDias($request->validated('dias'));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['dias' => [$e->getMessage()]],
            ], 422);
        }

        return new FichaResource($ficha->load(['persona', 'periodo.dias', 'dias.diaPeriodo']));
    }

    /**
     * Rechazo con el motivo escrito para quien está en la caja con alguien enfrente.
     */
    private function rechazar(string $mensaje): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => ['qr_token' => [$mensaje]],
        ], 422);
    }
}
