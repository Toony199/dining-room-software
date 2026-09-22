<?php

namespace Tests\Feature;

use App\Http\Requests\ImprimirGafetesRequest;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Impresión de varios gafetes en una hoja (§4.1).
 *
 * Se piden personas, no gafetes: el servidor resuelve cuál es el gafete vigente de cada una y
 * reporta a quienes no se pueden imprimir, para que la vista previa lo advierta antes de gastar
 * la hoja.
 */
class GafeteLoteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, int>  $personas
     */
    private function imprimir(array $personas)
    {
        return $this->getJson('/api/gafetes/impresion?'.http_build_query(['personas' => $personas]));
    }

    // --- Lo que sí se imprime --------------------------------------------------

    public function test_entrega_los_gafetes_de_las_personas_seleccionadas(): void
    {
        $this->actuandoComo('gafetes.reimprimir');
        $ana = Persona::factory()->create();
        $beto = Persona::factory()->create();
        $gafeteDeAna = $ana->emitirGafete();
        $gafeteDeBeto = $beto->emitirGafete();

        $respuesta = $this->imprimir([$ana->id, $beto->id])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.omitidos', []);

        $tokens = array_column($respuesta->json('data'), 'qr_token');
        $this->assertEqualsCanonicalizing([$gafeteDeAna->qr_token, $gafeteDeBeto->qr_token], $tokens);
    }

    public function test_los_ordena_como_el_listado(): void
    {
        // La hoja sale en el mismo orden en que se ven en pantalla; si no, cotejar 27 gafetes
        // impresos contra la lista es un rompecabezas.
        $this->actuandoComo('gafetes.reimprimir');

        $personas = collect(['Zamora', 'Alcaraz', 'Medina'])->map(function (string $apellido) {
            $persona = Persona::factory()->create(['primer_apellido' => $apellido]);
            $persona->emitirGafete();

            return $persona;
        });

        $this->imprimir($personas->pluck('id')->all())
            ->assertOk()
            ->assertJsonPath('data.0.persona.nombre_completo', fn ($nombre) => str_contains($nombre, 'Alcaraz'))
            ->assertJsonPath('data.1.persona.nombre_completo', fn ($nombre) => str_contains($nombre, 'Medina'))
            ->assertJsonPath('data.2.persona.nombre_completo', fn ($nombre) => str_contains($nombre, 'Zamora'));
    }

    public function test_imprime_el_gafete_vigente_y_no_el_reemplazado(): void
    {
        // Entre que se cargó el listado y se mandó a imprimir, alguien pudo reponerlo.
        $this->actuandoComo('gafetes.reimprimir');
        $persona = Persona::factory()->create();
        $anterior = $persona->emitirGafete();
        $vigente = $persona->emitirGafete();

        $respuesta = $this->imprimir([$persona->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.qr_token', $vigente->qr_token);

        $this->assertStringNotContainsString($anterior->qr_token, $respuesta->getContent());
    }

    // --- Lo que se omite, y por qué --------------------------------------------

    public function test_omite_a_quien_no_tiene_gafete_y_dice_por_que(): void
    {
        $this->actuandoComo('gafetes.reimprimir');
        $conGafete = Persona::factory()->create();
        $conGafete->emitirGafete();
        $sinGafete = Persona::factory()->create(['nombre' => 'Ana', 'primer_apellido' => 'Solano']);

        $this->imprimir([$conGafete->id, $sinGafete->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'meta.omitidos')
            ->assertJsonPath('meta.omitidos.0.id', $sinGafete->id)
            ->assertJsonPath('meta.omitidos.0.nombre_completo', fn ($nombre) => str_contains($nombre, 'Solano'))
            ->assertJsonPath('meta.omitidos.0.motivo', fn ($motivo) => str_contains($motivo, 'No tiene gafete activo'));
    }

    public function test_omite_a_la_persona_dada_de_baja(): void
    {
        // §3.3: su gafete no funciona, así que imprimirlo solo produce una credencial inútil.
        $this->actuandoComo('gafetes.reimprimir');
        $persona = Persona::factory()->create();
        $persona->emitirGafete();
        $persona->update(['estado' => 'INACTIVO']);

        $this->imprimir([$persona->id])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.omitidos.0.motivo', fn ($motivo) => str_contains($motivo, 'dada de baja'));
    }

    public function test_reporta_a_la_persona_que_no_existe(): void
    {
        $this->actuandoComo('gafetes.reimprimir');

        $this->imprimir([99999])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.omitidos.0.id', 99999)
            ->assertJsonPath('meta.omitidos.0.nombre_completo', null);
    }

    // --- Límites de la petición -------------------------------------------------

    public function test_no_imprime_mas_de_sesenta_a_la_vez(): void
    {
        // Son unas siete hojas; más que eso es una selección hecha sin mirar.
        $this->actuandoComo('gafetes.reimprimir');

        $this->imprimir(range(1, ImprimirGafetesRequest::MAXIMO + 1))
            ->assertStatus(422)
            ->assertJsonPath('errors.personas.0', 'No se pueden imprimir más de 60 gafetes a la vez.');
    }

    public function test_exige_al_menos_una_persona(): void
    {
        $this->actuandoComo('gafetes.reimprimir');

        $this->getJson('/api/gafetes/impresion')
            ->assertStatus(422)
            ->assertJsonPath('errors.personas.0', 'Selecciona al menos una persona.');
    }

    public function test_rechaza_una_seleccion_repetida(): void
    {
        $this->actuandoComo('gafetes.reimprimir');
        $persona = Persona::factory()->create();
        $persona->emitirGafete();

        $this->imprimir([$persona->id, $persona->id])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('personas.0');
    }

    // --- Permisos ----------------------------------------------------------------

    public function test_quien_emite_tambien_puede_imprimir_en_lote(): void
    {
        $this->actuandoComo('gafetes.emitir');
        $persona = Persona::factory()->create();
        $persona->emitirGafete();

        $this->imprimir([$persona->id])->assertOk();
    }

    public function test_ver_gafetes_no_alcanza_para_imprimir_en_lote(): void
    {
        // El token del QR solo sale por impresión: con él se fabrica un gafete que funciona.
        $this->actuandoComo('gafetes.ver');
        $persona = Persona::factory()->create();
        $persona->emitirGafete();

        $this->imprimir([$persona->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_exige_sesion(): void
    {
        $persona = Persona::factory()->create();
        $persona->emitirGafete();

        $this->imprimir([$persona->id])->assertUnauthorized();
    }
}
