<?php

namespace App\Http\Controllers;

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
        // `can:` solo admite una clave; aquí basta cualquiera de las dos.
        if (! Gate::any(['gafetes.emitir', 'gafetes.reimprimir'])) {
            throw new AuthorizationException;
        }

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

    private function rechazarImpresion(string $mensaje): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => ['gafete' => [$mensaje]],
        ], 422);
    }
}
