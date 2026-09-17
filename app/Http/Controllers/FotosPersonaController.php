<?php

namespace App\Http\Controllers;

use App\Http\Requests\FotoPersonaRequest;
use App\Http\Resources\PersonasResource;
use App\Models\Persona;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotografía de una persona (§3.1).
 *
 * Es privada: el archivo vive en el disco `local`, fuera de `public/`, y solo sale por este
 * controlador, a quien tiene sesión y permiso. Es un dato personal del empleado.
 *
 * Ese disco tiene `serve` activado, pero Laravel solo lo sirve con URL firmada (ver
 * Illuminate\Filesystem\ServeFile) y la aplicación nunca genera una, así que las fotos no son
 * accesibles por su ruta.
 */
class FotosPersonaController extends Controller
{
    /**
     * Entrega la fotografía.
     *
     * La puede ver quien puede consultar al personal o trabajar con gafetes: el formulario de la
     * persona y la vista previa del gafete la muestran.
     */
    public function show(Persona $persona): StreamedResponse|JsonResponse
    {
        if (! Gate::any(['colaboradores.ver', 'gafetes.ver', 'gafetes.emitir', 'gafetes.reimprimir'])) {
            throw new AuthorizationException;
        }

        if (! $persona->tieneFoto()) {
            return response()->json(['message' => 'La persona no tiene fotografía.'], 404);
        }

        return Storage::disk(Persona::DISCO_FOTOS)->response($persona->foto_path, null, [
            // Dato personal: que no lo guarden cachés compartidas ni proxies.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Toma o reemplaza la fotografía (§3.1).
     *
     * Es parte de editar a la persona, así que exige `colaboradores.editar`. El formulario la
     * sube justo después de guardar el alta o la edición.
     *
     * No toca el gafete: el QR no depende de la foto, así que el gafete que la persona ya tiene
     * sigue funcionando; el impreso, eso sí, queda desactualizado hasta reimprimirlo.
     */
    public function store(FotoPersonaRequest $request, Persona $persona): PersonasResource
    {
        $persona->asignarFoto($request->file('foto'));

        return new PersonasResource($persona->fresh()->load(['departamento', 'cuenta.rol', 'gafeteActivo']));
    }
}
