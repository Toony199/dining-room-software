<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImprimirGafetesRequest;
use App\Http\Resources\GafeteImpresionResource;
use App\Http\Resources\GafeteResource;
use App\Models\Gafete;
use App\Models\Persona;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Gafetes con QR (§4). No hay DELETE ni edición: un gafete solo se reemplaza (§4.3), y el
 * historial se conserva completo.
 */
class GafetesController extends Controller
{
    /**
     * Historial de gafetes de una persona, del más reciente al más antiguo (§4.3).
     */
    public function index(Persona $persona): AnonymousResourceCollection
    {
        return GafeteResource::collection($persona->gafetes()->get());
    }

    /**
     * Emite un gafete, o lo repone si ya tenía uno: el anterior deja de funcionar de inmediato
     * (§4.3).
     */
    public function store(Persona $persona): JsonResponse
    {
        // Una persona dada de baja no puede usar su gafete (§3.3): emitirle uno no serviría.
        if ($persona->estado !== 'ACTIVO') {
            return response()->json([
                'message' => 'No se puede emitir un gafete para una persona dada de baja: no podría usarlo.',
                'errors' => ['persona' => ['La persona está dada de baja.']],
            ], 422);
        }

        $gafete = $persona->emitirGafete();

        return (new GafeteResource($gafete))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Datos para imprimir el gafete, incluido el token que codifica el QR.
     *
     * Lo puede pedir quien emite (imprime justo después de emitir) o quien solo reimprime.
     */
    public function impresion(Gafete $gafete): GafeteImpresionResource|JsonResponse
    {
        $this->exigirPermisoDeImpresion();

        $gafete->load('persona.departamento');

        // Imprimir un gafete que no funciona solo produce una credencial inútil en manos de
        // alguien. Un gafete reemplazado nunca vuelve a funcionar; el de una persona de baja,
        // hasta que la reactiven.
        if (! $gafete->estaActivo()) {
            return $this->rechazarImpresion('Este gafete fue reemplazado y ya no funciona. Imprime el gafete activo de la persona.');
        }

        if ($gafete->persona->estado !== 'ACTIVO') {
            return $this->rechazarImpresion('La persona está dada de baja: su gafete no funciona mientras no la reactiven.');
        }

        return new GafeteImpresionResource($gafete);
    }

    /**
     * Datos para imprimir varios gafetes en una misma hoja (§4.1).
     *
     * Recibe personas, no gafetes: quien selecciona en el listado elige personas, y cuál es su
     * gafete vigente lo resuelve el servidor. Así una reposición hecha por alguien más entre que
     * se cargó el listado y se mandó a imprimir no hace que salga impreso un gafete muerto.
     *
     * Devuelve solo los imprimibles, y aparte a quienes quedaron fuera con su motivo, para que la
     * vista previa lo advierta antes de gastar la hoja.
     */
    public function impresionEnLote(ImprimirGafetesRequest $request): AnonymousResourceCollection
    {
        $this->exigirPermisoDeImpresion();

        /** @var array<int, int> $seleccionadas */
        $seleccionadas = $request->validated('personas');

        $personas = Persona::query()
            ->with(['departamento', 'gafeteActivo'])
            ->whereIn('id', $seleccionadas)
            // El mismo orden del listado: la hoja sale como se ve en pantalla.
            ->orderBy('primer_apellido')
            ->orderBy('segundo_apellido')
            ->orderBy('nombre')
            ->get();

        $gafetes = [];
        $omitidos = [];

        foreach ($personas as $persona) {
            $motivo = $this->motivoParaNoImprimir($persona);

            if ($motivo !== null) {
                $omitidos[] = [
                    'id' => $persona->id,
                    'nombre_completo' => $persona->nombre_completo,
                    'motivo' => $motivo,
                ];

                continue;
            }

            // El recurso lee la persona desde el gafete; se la damos ya cargada para no repetir
            // una consulta por cada uno.
            $gafetes[] = $persona->gafeteActivo->setRelation('persona', $persona);
        }

        // Una persona que ya no está no debería llegar desde el listado, pero si alguien la dio
        // de baja físicamente o la selección venía de otra parte, se dice en vez de callarlo.
        foreach (array_diff($seleccionadas, $personas->modelKeys()) as $id) {
            $omitidos[] = [
                // Llegó por la URL, así que es texto: el resto de la API entrega ids numéricos.
                'id' => (int) $id,
                'nombre_completo' => null,
                'motivo' => 'No se encontró a la persona.',
            ];
        }

        return GafeteImpresionResource::collection($gafetes)
            ->additional(['meta' => ['omitidos' => $omitidos]]);
    }

    /**
     * Por qué no se puede imprimir el gafete de esta persona, o null si sí se puede.
     */
    private function motivoParaNoImprimir(Persona $persona): ?string
    {
        if ($persona->estado !== 'ACTIVO') {
            return 'Está dada de baja: su gafete no funciona mientras no la reactiven.';
        }

        if ($persona->gafeteActivo === null) {
            return 'No tiene gafete activo: emíteselo desde su fila del listado.';
        }

        return null;
    }

    /**
     * `can:` solo admite una clave; para imprimir basta cualquiera de las dos.
     */
    private function exigirPermisoDeImpresion(): void
    {
        if (! Gate::any(['gafetes.emitir', 'gafetes.reimprimir'])) {
            throw new AuthorizationException;
        }
    }

    private function rechazarImpresion(string $mensaje): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => ['gafete' => [$mensaje]],
        ], 422);
    }
}
