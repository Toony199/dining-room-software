<?php

namespace Tests\Feature;

use App\Models\GafeteDiseno;
use App\Models\Permiso;
use App\Models\Persona;
use App\Support\PlantillaGafete;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Diseño del gafete (§4.1): un solo formato para todos, subido como borrador y activado aparte.
 * La lectura y la limpieza del SVG se prueban en Tests\Unit\PlantillaGafeteTest; aquí, el flujo y
 * los permisos.
 */
class DisenoGafeteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, string>
     */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GafeteDiseno::DISCO);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /** Un diseño válido: la plantilla del proyecto con un fondo de otro color y $extra dentro. */
    private function disenoValido(string $extra = ''): string
    {
        return str_replace(
            '<rect width="54" height="85.6" fill="#ffffff"/>',
            '<rect width="54" height="85.6" fill="#fef3c7"/>'.$extra,
            (string) file_get_contents(PlantillaGafete::rutaPredeterminada()),
        );
    }

    /** Archivo subido real (ver FotoPersonaTest: el falso de Laravel deduce el tipo del nombre). */
    private function archivo(string $contenido, string $nombre = 'diseno.svg'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'diseno');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function subir(?UploadedFile $archivo)
    {
        return $this->post('/api/gafetes/disenos', $archivo ? ['archivo' => $archivo] : [], ['Accept' => 'application/json']);
    }

    private function borrador(): GafeteDiseno
    {
        return GafeteDiseno::findOrFail($this->subir($this->archivo($this->disenoValido()))->assertCreated()->json('data.id'));
    }

    /**
     * Diseño creado directamente, sin pasar por la subida: para las pruebas cuya sesión no puede
     * subir diseños (actuandoComo() solo se llama una vez por prueba).
     */
    private function crearDiseno(bool $activo = false): GafeteDiseno
    {
        $plantilla = PlantillaGafete::desdeContenido($this->disenoValido());
        $ruta = 'disenos-gafete/'.Str::ulid().'.svg';
        Storage::disk(GafeteDiseno::DISCO)->put($ruta, $plantilla->svg());

        $diseno = GafeteDiseno::create([
            'nombre_archivo' => 'diseno.svg',
            'archivo_path' => $ruta,
            'zonas' => $plantilla->zonas(),
            'advertencias' => $plantilla->advertencias(),
        ]);

        if ($activo) {
            $diseno->activar();
        }

        return $diseno;
    }

    // --- El diseño vigente -------------------------------------------------------

    public function test_sin_disenos_subidos_se_usa_el_del_proyecto(): void
    {
        $this->actuandoComo('gafetes.ver');

        $this->getJson('/api/gafetes/diseno')
            ->assertOk()
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.predeterminado', true)
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.zonas.qr.ancho', 22)
            ->assertJsonPath('data.fondo_url', fn ($url) => str_starts_with($url, '/api/gafetes/disenos/predeterminado/fondo?v='));
    }

    // --- Subir -------------------------------------------------------------------

    public function test_un_diseno_subido_queda_como_borrador(): void
    {
        // Uno mal armado dejaría de imprimir bien todos los gafetes al instante: primero se revisa.
        $this->actuandoComo('gafetes.disenar');

        $this->subir($this->archivo($this->disenoValido(), 'marca-2026.svg'))
            ->assertCreated()
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.predeterminado', false)
            ->assertJsonPath('data.nombre_archivo', 'marca-2026.svg')
            ->assertJsonPath('data.subido_por', fn ($nombre) => str_contains($nombre, self::APELLIDO_DE_SESION));

        $this->getJson('/api/gafetes/diseno')->assertJsonPath('data.predeterminado', true);
    }

    public function test_guarda_el_svg_limpio_y_no_el_original(): void
    {
        $this->actuandoComo('gafetes.disenar');

        $respuesta = $this->subir($this->archivo($this->disenoValido('<script>alert(1)</script>')))->assertCreated();

        $guardado = Storage::disk(GafeteDiseno::DISCO)->get(GafeteDiseno::findOrFail($respuesta->json('data.id'))->archivo_path);
        $this->assertStringNotContainsString('script', $guardado);
        $this->assertStringNotContainsString('id="zona-qr"', $guardado);
        $this->assertStringContainsString('#fef3c7', $guardado);
        $respuesta->assertJsonPath('data.advertencias', fn ($avisos) => str_contains(implode(' ', $avisos), 'script'));
    }

    public function test_un_diseno_invalido_se_rechaza_sin_guardar_nada(): void
    {
        $this->actuandoComo('gafetes.disenar');
        $sinQr = preg_replace('#<rect id="zona-qr"[^>]*/>#', '', $this->disenoValido());

        $this->subir($this->archivo($sinQr))
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', fn ($mensaje) => str_contains($mensaje, 'Falta la zona «zona-qr»'));

        $this->assertSame(0, GafeteDiseno::count());
        $this->assertSame([], Storage::disk(GafeteDiseno::DISCO)->allFiles());
    }

    public function test_solo_acepta_archivos_svg(): void
    {
        $this->actuandoComo('gafetes.disenar');

        $this->subir($this->archivo($this->disenoValido(), 'diseno.png'))
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El diseño debe ser un archivo SVG.');
    }

    public function test_rechaza_disenos_de_mas_de_1_mb(): void
    {
        $this->actuandoComo('gafetes.disenar');

        $this->subir($this->archivo($this->disenoValido('<desc>'.str_repeat('a', 1100 * 1024).'</desc>')))
            ->assertStatus(422)
            ->assertJsonPath('errors.archivo.0', 'El diseño no debe pesar más de 1 MB.');
    }

    public function test_exige_adjuntar_el_archivo(): void
    {
        $this->actuandoComo('gafetes.disenar');

        $this->subir(null)->assertStatus(422)->assertJsonValidationErrorFor('archivo');
    }

    // --- Activar y restablecer ----------------------------------------------------------

    public function test_activar_pone_en_uso_el_diseno(): void
    {
        $this->actuandoComo('gafetes.disenar');
        $diseno = $this->borrador();

        $this->patchJson("/api/gafetes/disenos/{$diseno->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.activado_en', fn ($fecha) => $fecha !== null);

        $this->getJson('/api/gafetes/diseno')
            ->assertJsonPath('data.id', $diseno->id)
            ->assertJsonPath('data.predeterminado', false);
    }

    public function test_solo_un_diseno_queda_activo_y_se_puede_volver_a_uno_anterior(): void
    {
        // §4: un formato único para todos.
        $this->actuandoComo('gafetes.disenar');
        $primero = $this->borrador();
        $segundo = $this->borrador();

        $this->patchJson("/api/gafetes/disenos/{$primero->id}/activar")->assertOk();
        $this->patchJson("/api/gafetes/disenos/{$segundo->id}/activar")->assertOk();
        $this->assertSame([$segundo->id], GafeteDiseno::where('activo', true)->pluck('id')->all());

        $this->patchJson("/api/gafetes/disenos/{$primero->id}/activar")->assertOk();
        $this->assertSame([$primero->id], GafeteDiseno::where('activo', true)->pluck('id')->all());
    }

    public function test_restablecer_vuelve_al_diseno_del_proyecto(): void
    {
        $this->actuandoComo('gafetes.disenar');
        $diseno = $this->borrador();
        $diseno->activar();

        $this->patchJson('/api/gafetes/disenos/restablecer')
            ->assertOk()
            ->assertJsonPath('data.predeterminado', true);

        $this->getJson('/api/gafetes/diseno')->assertJsonPath('data.predeterminado', true);
        // No se borra: queda en el historial para volver a activarlo.
        $this->assertFalse($diseno->fresh()->activo);
    }

    public function test_el_historial_va_del_mas_reciente_al_mas_antiguo(): void
    {
        $this->actuandoComo('gafetes.disenar');
        $primero = $this->borrador();
        $this->travel(1)->minute();
        $segundo = $this->borrador();

        $this->getJson('/api/gafetes/disenos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $segundo->id)
            ->assertJsonPath('data.1.id', $primero->id)
            ->assertJsonPath('data.0.subido_por', fn ($nombre) => str_contains($nombre, self::APELLIDO_DE_SESION));
    }

    // --- El fondo ----------------------------------------------------------------------

    public function test_el_fondo_se_sirve_con_cabeceras_que_impiden_ejecutar_codigo(): void
    {
        // Por si alguien abre la dirección directamente: la aplicación lo muestra como <img>.
        $this->actuandoComo('gafetes.disenar');
        $diseno = $this->borrador();

        $respuesta = $this->get("/api/gafetes/disenos/{$diseno->id}/fondo")->assertOk();

        $this->assertStringStartsWith('image/svg+xml', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", $respuesta->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('sandbox', $respuesta->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('#fef3c7', $respuesta->getContent());
    }

    public function test_el_fondo_de_un_borrador_solo_lo_ve_quien_disena(): void
    {
        $borrador = $this->crearDiseno();
        $activo = $this->crearDiseno(activo: true);

        $this->actuandoComo('gafetes.reimprimir');

        $this->getJson("/api/gafetes/disenos/{$borrador->id}/fondo")->assertForbidden();
        $this->get("/api/gafetes/disenos/{$activo->id}/fondo")->assertOk();
    }

    public function test_el_fondo_del_proyecto_lo_ve_quien_trabaja_con_gafetes(): void
    {
        $this->actuandoComo('gafetes.ver');

        $this->get('/api/gafetes/disenos/predeterminado/fondo')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_el_fondo_del_proyecto_exige_algun_permiso_de_gafetes(): void
    {
        $this->actuandoComo('usuarios.ver');

        $this->getJson('/api/gafetes/disenos/predeterminado/fondo')->assertForbidden();
    }

    public function test_la_plantilla_trae_las_zonas_para_disenar(): void
    {
        $this->actuandoComo('gafetes.disenar');

        $respuesta = $this->get('/api/gafetes/disenos/plantilla')->assertOk();

        $this->assertStringContainsString('attachment', $respuesta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('plantilla-gafete.svg', $respuesta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('id="zona-qr"', (string) file_get_contents($respuesta->getFile()->getPathname()));
    }

    public function test_la_api_no_expone_la_ruta_del_archivo(): void
    {
        $this->actuandoComo('gafetes.disenar');
        $diseno = $this->borrador();

        $contenido = $this->getJson('/api/gafetes/disenos')->assertJsonMissingPath('data.0.archivo_path')->getContent();

        $this->assertStringNotContainsString($diseno->archivo_path, $contenido);
    }

    // --- Permisos --------------------------------------------------------------------------

    public function test_subir_activar_y_restablecer_exigen_gafetes_disenar(): void
    {
        $diseno = $this->crearDiseno();

        // Emitir e imprimir gafetes no alcanza para cambiar el de todos.
        $this->actuandoComo('gafetes.emitir', 'gafetes.reimprimir');

        $this->subir($this->archivo($this->disenoValido()))->assertForbidden();
        $this->patchJson("/api/gafetes/disenos/{$diseno->id}/activar")->assertForbidden();
        $this->patchJson('/api/gafetes/disenos/restablecer')->assertForbidden();
        $this->getJson('/api/gafetes/disenos')->assertForbidden();
        $this->getJson('/api/gafetes/disenos/plantilla')->assertForbidden();

        $this->assertFalse($diseno->fresh()->activo);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function permisosDeGafetes(): array
    {
        return [
            'ver' => ['gafetes.ver'],
            'emitir' => ['gafetes.emitir'],
            'reimprimir' => ['gafetes.reimprimir'],
            'disenar' => ['gafetes.disenar'],
        ];
    }

    #[DataProvider('permisosDeGafetes')]
    public function test_el_diseno_vigente_lo_ve_cualquiera_que_trabaje_con_gafetes(string $clave): void
    {
        // El modal y la hoja de impresión lo necesitan para dibujar el gafete.
        $this->actuandoComo($clave);

        $this->getJson('/api/gafetes/diseno')->assertOk();
    }

    public function test_el_diseno_vigente_exige_algun_permiso_de_gafetes(): void
    {
        $this->actuandoComo('colaboradores.ver');

        $this->getJson('/api/gafetes/diseno')->assertForbidden();
    }

    public function test_exige_sesion(): void
    {
        $this->getJson('/api/gafetes/diseno')->assertUnauthorized();
        $this->getJson('/api/gafetes/disenos/predeterminado/fondo')->assertUnauthorized();
    }

    public function test_el_catalogo_incluye_el_permiso_de_disenar(): void
    {
        $this->seed(PermisoSeeder::class);

        $this->assertTrue(Permiso::where('clave', 'gafetes.disenar')->exists());
    }

    // --- Nombre en el gafete -----------------------------------------------------------

    public function test_el_gafete_lleva_nombre_y_primer_apellido(): void
    {
        // El QR y la foto identifican; el nombre corto cabe sin achicarse hasta ser ilegible.
        $this->actuandoComo('gafetes.reimprimir');
        $persona = Persona::factory()->create([
            'nombre' => 'María Guadalupe',
            'primer_apellido' => 'Hernández',
            'segundo_apellido' => 'de la Cruz',
        ]);
        $gafete = $persona->emitirGafete();

        $this->getJson("/api/gafetes/{$gafete->id}/impresion")
            ->assertOk()
            ->assertJsonPath('data.persona.nombre_gafete', 'María Guadalupe Hernández')
            ->assertJsonPath('data.persona.nombre_completo', 'María Guadalupe Hernández de la Cruz');
    }
}
