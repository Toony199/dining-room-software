<?php

namespace App\Http\Controllers;

use App\Models\DerechoConsumo;
use App\Models\Gafete;
use App\Models\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Validación del consumo en la entrada del comedor (§16).
 *
 * El colaborador escanea su gafete y el sistema responde una sola cosa: si hoy puede comer. Todas
 * las respuestas son 200 con su resultado, incluidas las negativas: para quien atiende la entrada,
 * "no pagó ese día" no es un error de la petición, es la respuesta.
 *
 * Usar un derecho lo consume para siempre (§2.3): no se transfiere, no se recorre a otro día y no
 * se vuelve a usar.
 */
class ConsumoController extends Controller
{
    /**
     * Paso único (§16): identificar, buscar el derecho de hoy y decidir.
     */
    public function validar(Request $request): JsonResponse
    {
        $request->validate(
            ['qr_token' => ['required', 'string', 'max:40']],
            ['qr_token.required' => 'Escanea el gafete.'],
        );

        $gafete = Gafete::query()->where('qr_token', $request->string('qr_token'))->with('persona')->first();

        if (! $gafete) {
            return $this->respuesta('RECHAZADO', 'GAFETE_DESCONOCIDO', 'No reconocemos ese gafete.');
        }

        if (! $gafete->estaActivo()) {
            return $this->respuesta('RECHAZADO', 'GAFETE_REEMPLAZADO', 'Ese gafete fue reemplazado. Usa el más reciente.');
        }

        $persona = $gafete->persona;

        // §3.3: una persona dada de baja pierde sus capacidades operativas, aunque haya pagado.
        if ($persona->estado !== 'ACTIVO') {
            return $this->respuesta('RECHAZADO', 'PERSONA_INACTIVA', 'Ese registro está dado de baja. Acude con el área de personal.', $persona);
        }

        $derecho = DerechoConsumo::deHoy($persona);

        // §16.3: no compró hoy. Es lo más común de los rechazos.
        if (! $derecho) {
            return $this->respuesta('RECHAZADO', 'NO_PAGADO', 'Hoy no tiene comida pagada.', $persona);
        }

        // §16.4: pagó un día que ya pasó, no este. Los derechos no se recorren.
        if ($derecho->estado === DerechoConsumo::VENCIDO) {
            return $this->respuesta('RECHAZADO', 'VENCIDO', 'Ese derecho venció y no se puede usar.', $persona);
        }

        // §16.2: ya comió hoy.
        if ($derecho->estado === DerechoConsumo::UTILIZADO) {
            return $this->respuesta(
                'RECHAZADO',
                'YA_UTILIZADO',
                'Ya registró su comida de hoy'.($derecho->utilizado_en ? ' a las '.$derecho->utilizado_en->format('H:i') : '').'.',
                $persona,
                $derecho,
            );
        }

        // §16.1: pasa, y el derecho queda consumido.
        if (! $derecho->usar($request->user())) {
            // Otro lector lo gastó entre la consulta y ahora: se responde lo mismo que vería el
            // segundo de la fila.
            $derecho->refresh();

            return $this->respuesta('RECHAZADO', 'YA_UTILIZADO', 'Ya registró su comida de hoy.', $persona, $derecho);
        }

        return $this->respuesta('PERMITIDO', 'CONSUMO_REGISTRADO', 'Puede pasar.', $persona, $derecho);
    }

    /**
     * Cómo va el servicio de hoy: cuántos pagaron, cuántos ya comieron y quién acaba de pasar.
     *
     * Lo mira la cocina para saber cuánto falta, y sirve para cotejar al cierre.
     */
    public function delDia(Request $request): JsonResponse
    {
        $fecha = Carbon::parse($request->date('fecha') ?? today())->startOfDay();

        $derechos = DerechoConsumo::query()
            ->whereHas('diaPeriodo', fn ($dia) => $dia->whereDate('fecha', $fecha))
            ->with(['persona', 'validadoPor.persona'])
            ->get();

        return response()->json([
            'data' => [
                'fecha' => $fecha->toDateString(),
                'pagados' => $derechos->count(),
                'servidos' => $derechos->where('estado', DerechoConsumo::UTILIZADO)->count(),
                'por_servir' => $derechos->where('estado', DerechoConsumo::VIGENTE)->count(),
                'ultimos' => $derechos
                    ->where('estado', DerechoConsumo::UTILIZADO)
                    ->sortByDesc('utilizado_en')
                    ->take(20)
                    ->map(fn (DerechoConsumo $derecho) => [
                        'persona' => $derecho->persona?->nombre_completo,
                        'numero_empleado' => $derecho->persona?->numero_empleado,
                        'utilizado_en' => $derecho->utilizado_en,
                        'validado_por' => $derecho->validadoPor?->persona?->nombre_completo,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * Una sola forma de respuesta, para que la pantalla no tenga que adivinar: el resultado manda
     * el color, el motivo permite distinguir los casos y el mensaje es lo que se lee en grande.
     */
    private function respuesta(
        string $resultado,
        string $motivo,
        string $mensaje,
        ?Persona $persona = null,
        ?DerechoConsumo $derecho = null,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'resultado' => $resultado,
                'motivo' => $motivo,
                'mensaje' => $mensaje,
                'persona' => $persona ? [
                    'nombre' => $persona->nombre_gafete,
                    'numero_empleado' => $persona->numero_empleado,
                ] : null,
                'utilizado_en' => $derecho?->utilizado_en,
            ],
        ]);
    }
}
