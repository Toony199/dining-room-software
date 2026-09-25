<?php

namespace App\Http\Controllers;

use App\Http\Requests\KioscoRequest;
use App\Http\Resources\FichaResource;
use App\Models\Ficha;
use App\Models\Gafete;
use App\Models\Periodo;
use App\Models\Persona;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Kiosco de generación de fichas (§10, §11).
 *
 * Es la única parte de la API sin sesión administrativa, y a propósito (§10.1): frente al kiosco
 * hay un colaborador, no un usuario del sistema. Se identifica con el QR de su gafete y solo puede
 * hacer dos cosas: ver sus días y generar su ficha.
 *
 * Por eso cada respuesta habla únicamente de quien escaneó: nunca lista a terceros, ni catálogos, ni
 * nada administrativo. Las rutas van limitadas por frecuencia, porque están abiertas.
 */
class KioscoController extends Controller
{
    /**
     * Paso 1 y 2 (§11): identifica a la persona por su gafete y le dice qué puede pedir.
     */
    public function identificar(KioscoRequest $request): JsonResponse
    {
        try {
            $persona = $this->personaDelGafete($request->string('qr_token')->toString());
            $periodo = $this->periodoAbierto();
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage());
        }

        $ficha = Ficha::query()
            ->where('persona_id', $persona->getKey())
            ->where('periodo_id', $periodo->getKey())
            ->valida()
            ->with(['dias.diaPeriodo', 'periodo'])
            ->first();

        return response()->json([
            'data' => [
                'persona' => [
                    // Lo justo para que se reconozca en la pantalla y sepa que es su ficha.
                    'nombre' => $persona->nombre_gafete,
                    'numero_empleado' => $persona->numero_empleado,
                ],
                'periodo' => [
                    'id' => $periodo->id,
                    'fecha_inicio' => $periodo->fecha_inicio->toDateString(),
                    'fecha_fin' => $periodo->fecha_fin->toDateString(),
                    // Los días no disponibles también viajan: la pantalla los muestra bloqueados en
                    // vez de esconderlos, para que se vea que ese día no hay servicio (§11 paso 3).
                    'dias' => $periodo->dias->map(fn ($dia) => [
                        'id' => $dia->id,
                        'fecha' => $dia->fecha->toDateString(),
                        'dia_semana' => $dia->dia_semana,
                        'disponible' => $dia->disponible,
                        'motivo_indisponibilidad' => $dia->motivo_indisponibilidad,
                        'precio' => $dia->precio_aplicado,
                    ])->all(),
                ],
                // Si ya tiene ficha de esta semana, la pantalla se lo enseña en vez de dejarlo
                // elegir otra vez (§17.1).
                'ficha' => $ficha ? new FichaResource($ficha) : null,
            ],
        ]);
    }

    /**
     * Paso 3 y 4 (§11): genera la ficha con los días elegidos. Nace PENDIENTE: por sí sola no da
     * derecho a comer, eso lo hace el pago confirmado (§2.2).
     */
    public function generarFicha(KioscoRequest $request): JsonResponse
    {
        try {
            $persona = $this->personaDelGafete($request->string('qr_token')->toString());
            $periodo = $this->periodoAbierto();
            $ficha = Ficha::generar($persona, $periodo, $request->validated('dias'));
        } catch (RuntimeException $e) {
            return $this->rechazar($e->getMessage());
        }

        return (new FichaResource($ficha->load(['dias.diaPeriodo', 'persona', 'periodo'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Paso 1 (§11): el QR debe existir, el gafete estar activo y la persona también (§3.3).
     *
     * @throws RuntimeException
     */
    private function personaDelGafete(string $token): Persona
    {
        $gafete = Gafete::query()->where('qr_token', $token)->with('persona')->first();

        if (! $gafete) {
            throw new RuntimeException('No reconocimos ese gafete. Acude con el área de personal.');
        }

        if (! $gafete->estaActivo()) {
            throw new RuntimeException('Ese gafete fue reemplazado. Usa el que te entregaron más reciente.');
        }

        if ($gafete->persona->estado !== 'ACTIVO') {
            throw new RuntimeException('Tu registro está dado de baja. Acude con el área de personal.');
        }

        return $gafete->persona;
    }

    /**
     * Paso 2 (§11): el periodo que hoy admite fichas. Exige estado abierto y ventana corriendo, así
     * que un cierre que nadie ejecutó no deja pedir fuera de tiempo (§17.4).
     *
     * @throws RuntimeException
     */
    private function periodoAbierto(): Periodo
    {
        $periodo = Periodo::query()
            ->where('estado', Periodo::ABIERTO)
            ->whereDate('ventana_inicio', '<=', today())
            ->whereDate('ventana_fin', '>=', today())
            ->with('dias')
            ->orderBy('fecha_inicio')
            ->first();

        if (! $periodo) {
            throw new RuntimeException('Hoy no se pueden generar fichas. Consulta las fechas para pedir tu comida.');
        }

        if (! $periodo->dias->contains('disponible', true)) {
            throw new RuntimeException('La próxima semana no tiene días de servicio.');
        }

        return $periodo;
    }

    /**
     * Un solo formato de rechazo, con el motivo escrito para quien está frente al kiosco.
     */
    private function rechazar(string $mensaje): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => ['kiosco' => [$mensaje]],
        ], 422);
    }
}
