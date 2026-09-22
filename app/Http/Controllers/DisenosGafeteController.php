<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubirDisenoGafeteRequest;
use App\Http\Resources\DisenoGafeteResource;
use App\Models\GafeteDiseno;
use App\Support\PlantillaGafete;
use App\Support\PlantillaGafeteInvalida;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Diseño del gafete (§4.1). Un solo formato para todos (§4): los diseños subidos quedan como
 * borrador hasta que alguien los activa, y no se borran, para poder volver a uno anterior.
 */
class DisenosGafeteController extends Controller
{
    /**
     * Cabeceras de todo SVG que sale de aquí. La aplicación lo muestra como <img>, donde el
     * navegador ya no ejecuta nada; esto cubre a quien abra la dirección directamente: ni scripts,
     * ni recursos externos, solo estilos e imágenes y tipografías incrustadas.
     */
    private const CABECERAS_SVG = [
        'Content-Type' => 'image/svg+xml; charset=utf-8',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; sandbox",
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * El diseño con el que se imprimen los gafetes. Lo necesita quien ve, emite o imprime gafetes,
     * además de quien diseña.
     */
    public function vigente(): DisenoGafeteResource
    {
        $this->exigirAccesoAGafetes();

        return new DisenoGafeteResource(GafeteDiseno::vigente()->loadMissing('subidoPor.persona'));
    }

    /**
     * Historial de diseños subidos, del más reciente al más antiguo.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $disenos = GafeteDiseno::query()
            ->with('subidoPor.persona')
            ->latest()
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100))
            ->withQueryString();

        return DisenoGafeteResource::collection($disenos);
    }

    /**
     * Sube un diseño. Queda como borrador: se revisa en la vista previa y se activa aparte.
     */
    public function store(SubirDisenoGafeteRequest $request): JsonResponse
    {
        $archivo = $request->file('archivo');

        try {
            $plantilla = PlantillaGafete::desdeContenido((string) file_get_contents($archivo->getRealPath()));
        } catch (PlantillaGafeteInvalida $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['archivo' => [$e->getMessage()]],
            ], 422);
        }

        // Se guarda el SVG ya limpio, nunca el original.
        $ruta = 'disenos-gafete/'.Str::ulid().'.svg';
        Storage::disk(GafeteDiseno::DISCO)->put($ruta, $plantilla->svg());

        try {
            $diseno = GafeteDiseno::create([
                'nombre_archivo' => Str::limit($archivo->getClientOriginalName(), 150, ''),
                'archivo_path' => $ruta,
                'zonas' => $plantilla->zonas(),
                'advertencias' => $plantilla->advertencias(),
                'subido_por' => $request->user()->getKey(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk(GafeteDiseno::DISCO)->delete($ruta);

            throw $e;
        }

        return (new DisenoGafeteResource($diseno->load('subidoPor.persona')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Pone en uso un diseño: un borrador recién revisado o uno anterior del historial.
     */
    public function activar(GafeteDiseno $diseno): DisenoGafeteResource
    {
        $diseno->activar();

        return new DisenoGafeteResource($diseno->load('subidoPor.persona'));
    }

    /**
     * Vuelve al diseño que trae el proyecto.
     */
    public function restablecer(): DisenoGafeteResource
    {
        GafeteDiseno::restablecerPredeterminado();

        return new DisenoGafeteResource(GafeteDiseno::predeterminado());
    }

    /**
     * Fondo de un diseño subido. El activo lo ve quien trabaja con gafetes; un borrador o uno del
     * historial, solo quien diseña.
     */
    public function fondo(GafeteDiseno $diseno): Response
    {
        $diseno->activo ? $this->exigirAccesoAGafetes() : $this->exigirPermiso('gafetes.disenar');

        return $this->svg($diseno->fondo());
    }

    public function fondoPredeterminado(): Response
    {
        $this->exigirAccesoAGafetes();

        return $this->svg(GafeteDiseno::predeterminado()->fondo());
    }

    /**
     * La plantilla con sus zonas marcadas, para que quien diseña parta de ella.
     */
    public function plantilla(): BinaryFileResponse
    {
        return response()->download(
            PlantillaGafete::rutaPredeterminada(),
            'plantilla-gafete.svg',
            self::CABECERAS_SVG,
        );
    }

    private function svg(string $contenido): Response
    {
        return response($contenido, 200, self::CABECERAS_SVG + [
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Ver el diseño vigente: cualquiera de los permisos de gafetes. `can:` solo admite una clave.
     */
    private function exigirAccesoAGafetes(): void
    {
        if (! Gate::any(['gafetes.ver', 'gafetes.emitir', 'gafetes.reimprimir', 'gafetes.disenar'])) {
            throw new AuthorizationException;
        }
    }

    private function exigirPermiso(string $clave): void
    {
        if (! Gate::allows($clave)) {
            throw new AuthorizationException;
        }
    }
}
