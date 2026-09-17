<?php

namespace Tests\Feature;

use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fotografía de la persona (§3.1): privada, tomada en el alta o la edición, y la misma que
 * muestra su gafete.
 */
class FotoPersonaTest extends TestCase
{
    use RefreshDatabase;

    // Imágenes mínimas reales. No se generan con UploadedFile::fake()->image() porque eso
    // requiere GD, que el PHP de XAMPP no tiene activado.
    private const WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba debe escribir en storage/app/private de verdad.
        Storage::fake(Persona::DISCO_FOTOS);
    }

    /**
     * @var array<int, string>
     */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /**
     * Archivo subido real, no UploadedFile::fake(): el falso de Laravel deduce el tipo MIME del
     * nombre, así que un PHP llamado foto.webp pasaría por imagen y la prueba no demostraría
     * nada. El real lo deduce del contenido, igual que en producción.
     */
    private function archivo(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'foto');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    private function foto(string $tipo = 'webp', string $relleno = ''): UploadedFile
    {
        return $this->archivo("foto.{$tipo}", base64_decode($tipo === 'png' ? self::PNG : self::WEBP).$relleno);
    }

    private function subir(Persona $persona, ?UploadedFile $foto)
    {
        return $this->post(
            "/api/personas/{$persona->id}/foto",
            $foto ? ['foto' => $foto] : [],
            ['Accept' => 'application/json'],
        );
    }

    private function personaConFoto(): Persona
    {
        $persona = Persona::factory()->create();
        $persona->asignarFoto($this->foto());

        return $persona->fresh();
    }

    // --- Tomar y reemplazar --------------------------------------------------

    public function test_guarda_la_foto_en_el_disco_privado(): void
    {
        $this->actuandoComo('colaboradores.editar');
        $persona = Persona::factory()->create();

        $this->subir($persona, $this->foto())
            ->assertOk()
            ->assertJsonPath('data.foto_url', fn ($url) => str_starts_with($url, "/api/personas/{$persona->id}/foto?v="));

        $persona->refresh();
        $this->assertStringStartsWith("fotos/personas/{$persona->id}/", $persona->foto_path);
        Storage::disk(Persona::DISCO_FOTOS)->assertExists($persona->foto_path);
    }

    public function test_reemplazar_la_foto_borra_la_anterior(): void
    {
        // Es un dato personal: no se acumulan fotos viejas en el servidor.
        $this->actuandoComo('colaboradores.editar');
        $persona = $this->personaConFoto();
        $anterior = $persona->foto_path;

        $this->subir($persona, $this->foto())->assertOk();

        $persona->refresh();
        $this->assertNotSame($anterior, $persona->foto_path);
        Storage::disk(Persona::DISCO_FOTOS)->assertMissing($anterior);
        Storage::disk(Persona::DISCO_FOTOS)->assertExists($persona->foto_path);
    }

    public function test_cambiar_la_foto_no_toca_el_gafete(): void
    {
        // El QR no depende de la foto: el gafete que la persona ya tiene sigue funcionando.
        $this->actuandoComo('colaboradores.editar');
        $persona = Persona::factory()->create();
        $gafete = $persona->emitirGafete();

        $this->subir($persona, $this->foto())->assertOk();

        $this->assertSame(1, $persona->gafetes()->count());
        $this->assertTrue($gafete->fresh()->estaActivo());
        $this->assertSame($gafete->qr_token, $gafete->fresh()->qr_token);
    }

    public function test_acepta_png_como_respaldo(): void
    {
        // Para los navegadores que no saben codificar WebP.
        $this->actuandoComo('colaboradores.editar');

        $this->subir(Persona::factory()->create(), $this->foto('png'))->assertOk();
    }

    public function test_rechaza_archivos_que_no_son_imagen(): void
    {
        // El tipo se comprueba contra el contenido, no contra la extensión.
        $this->actuandoComo('colaboradores.editar');
        $disfrazado = $this->archivo('foto.webp', '<?php echo "hola";');

        $this->subir(Persona::factory()->create(), $disfrazado)
            ->assertStatus(422)
            ->assertJsonPath('errors.foto.0', 'La fotografía debe ser una imagen WebP, JPG o PNG.');
    }

    public function test_rechaza_fotos_de_mas_de_1_mb(): void
    {
        $this->actuandoComo('colaboradores.editar');

        $this->subir(Persona::factory()->create(), $this->foto('webp', str_repeat("\0", 1100 * 1024)))
            ->assertStatus(422)
            ->assertJsonPath('errors.foto.0', 'La fotografía no debe pesar más de 1 MB.');
    }

    public function test_exige_adjuntar_una_foto(): void
    {
        $this->actuandoComo('colaboradores.editar');

        $this->subir(Persona::factory()->create(), null)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('foto');
    }

    public function test_tomar_la_foto_exige_colaboradores_editar(): void
    {
        // Es parte de editar a la persona; ni crear personas ni emitir gafetes alcanzan.
        $this->actuandoComo('colaboradores.ver', 'colaboradores.crear', 'gafetes.emitir');
        $persona = Persona::factory()->create();

        $this->subir($persona, $this->foto())->assertForbidden();
        $this->assertNull($persona->fresh()->foto_path);
    }

    // --- Entrega privada -------------------------------------------------------

    public function test_se_entrega_a_quien_puede_ver_al_personal(): void
    {
        $persona = $this->personaConFoto();
        $this->actuandoComo('colaboradores.ver');

        $respuesta = $this->get("/api/personas/{$persona->id}/foto")->assertOk();

        $this->assertSame('image/webp', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $respuesta->headers->get('Cache-Control'));
    }

    public function test_se_entrega_a_quien_imprime_gafetes(): void
    {
        // La vista previa del gafete la muestra.
        $persona = $this->personaConFoto();
        $this->actuandoComo('gafetes.reimprimir');

        $this->get("/api/personas/{$persona->id}/foto")->assertOk();
    }

    public function test_entregarla_exige_permiso(): void
    {
        $persona = $this->personaConFoto();
        $this->actuandoComo('usuarios.ver');

        $this->getJson("/api/personas/{$persona->id}/foto")->assertForbidden();
    }

    public function test_entregarla_exige_sesion(): void
    {
        $persona = $this->personaConFoto();

        $this->getJson("/api/personas/{$persona->id}/foto")->assertUnauthorized();
    }

    public function test_sin_foto_responde_404(): void
    {
        $this->actuandoComo('colaboradores.ver');

        $this->getJson('/api/personas/'.Persona::factory()->create()->id.'/foto')->assertNotFound();
    }

    public function test_el_api_no_expone_la_ruta_del_archivo(): void
    {
        $persona = $this->personaConFoto();
        $this->actuandoComo('colaboradores.ver');

        $respuesta = $this->getJson("/api/personas/{$persona->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.foto_path');

        $this->assertStringNotContainsString('fotos/personas', $respuesta->getContent());
        $this->assertStringStartsWith('/api/personas/', $respuesta->json('data.foto_url'));
    }

    public function test_la_impresion_del_gafete_incluye_la_foto(): void
    {
        $persona = $this->personaConFoto();
        $gafete = $persona->emitirGafete();
        $this->actuandoComo('gafetes.reimprimir');

        $this->getJson("/api/gafetes/{$gafete->id}/impresion")
            ->assertOk()
            ->assertJsonPath('data.persona.foto_url', $persona->urlDeFoto());
    }

    public function test_un_valor_basura_no_cuenta_como_foto(): void
    {
        // Registros cargados a mano guardaron el texto 'null'.
        $persona = Persona::factory()->create(['foto_path' => 'null']);

        $this->assertFalse($persona->tieneFoto());
        $this->assertNull($persona->urlDeFoto());
    }
}
