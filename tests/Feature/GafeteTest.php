<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Gafete;
use App\Models\Permiso;
use App\Models\Persona;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de gafetes (§4): emisión, reposición, historial e impresión.
 */
class GafeteTest extends TestCase
{
    use RefreshDatabase;

    // --- Emisión y reposición ------------------------------------------------

    public function test_el_alta_de_una_persona_emite_su_gafete(): void
    {
        // §4: toda persona registrada debe contar con un gafete generado por el sistema.
        $this->actuandoComo('colaboradores.crear');

        $respuesta = $this->postJson('/api/personas', [
            'numero_empleado' => '7001',
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'departamento_id' => Departamento::factory()->create()->id,
        ])->assertCreated();

        $persona = Persona::findOrFail($respuesta->json('data.id'));

        $this->assertSame(1, $persona->gafetes()->count());
        $this->assertTrue($persona->gafeteActivo->estaActivo());
        $respuesta->assertJsonPath('data.gafete.id', $persona->gafeteActivo->id);
    }

    public function test_emite_un_gafete_a_una_persona_que_no_tiene(): void
    {
        $this->actuandoComo('gafetes.emitir');
        $persona = Persona::factory()->create();

        $this->postJson("/api/personas/{$persona->id}/gafetes")
            ->assertCreated()
            ->assertJsonPath('data.estado', 'ACTIVO')
            ->assertJsonPath('data.persona_id', $persona->id);
    }

    public function test_reponer_desactiva_el_anterior_y_deja_un_solo_activo(): void
    {
        // §4.3: el gafete anterior deja de funcionar inmediatamente.
        $this->actuandoComo('gafetes.emitir');
        $persona = Persona::factory()->create();
        $anterior = $persona->emitirGafete();

        $this->postJson("/api/personas/{$persona->id}/gafetes")->assertCreated();

        $this->assertSame('INACTIVO', $anterior->fresh()->estado);
        $this->assertSame(1, $persona->gafetes()->where('estado', 'ACTIVO')->count());
        $this->assertSame(2, $persona->gafetes()->count());
    }

    public function test_cada_emision_tiene_un_token_distinto(): void
    {
        // §4.3: el QR es único por gafete emitido, no por persona.
        $persona = Persona::factory()->create();

        $tokens = collect(range(1, 3))->map(fn () => $persona->emitirGafete()->qr_token);

        $this->assertCount(3, $tokens->unique());
        $this->assertNotContains($persona->numero_empleado, $tokens);
    }

    public function test_no_se_emite_un_gafete_a_una_persona_dada_de_baja(): void
    {
        // §3.3: una persona inactiva no puede usar su gafete.
        $this->actuandoComo('gafetes.emitir');
        $persona = Persona::factory()->inactiva()->create();

        $this->postJson("/api/personas/{$persona->id}/gafetes")
            ->assertStatus(422)
            ->assertJsonPath('errors.persona.0', 'La persona está dada de baja.');

        $this->assertSame(0, $persona->gafetes()->count());
    }

    public function test_dar_de_baja_a_la_persona_no_toca_su_gafete(): void
    {
        // El gafete no se copia el estado de la persona: el kiosco y el comedor comprueban los
        // dos (RN-20). Al reactivar a la persona, el mismo gafete vuelve a servir.
        $this->actuandoComo('colaboradores.desactivar');
        $persona = Persona::factory()->create();
        $gafete = $persona->emitirGafete();

        $this->patchJson("/api/personas/{$persona->id}/desactivar")->assertOk();

        $this->assertTrue($gafete->fresh()->estaActivo());
    }

    // --- Historial -----------------------------------------------------------

    public function test_el_historial_conserva_los_gafetes_anteriores(): void
    {
        $this->actuandoComo('gafetes.ver');
        $persona = Persona::factory()->create();
        $persona->emitirGafete();
        $persona->emitirGafete();
        $activo = $persona->emitirGafete();

        $this->getJson("/api/personas/{$persona->id}/gafetes")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $activo->id)
            ->assertJsonPath('data.0.estado', 'ACTIVO')
            ->assertJsonPath('data.1.estado', 'INACTIVO')
            ->assertJsonPath('data.2.estado', 'INACTIVO');
    }

    public function test_ni_el_historial_ni_la_persona_exponen_el_token(): void
    {
        // Con el token se fabrica un gafete que funciona: solo sale por la impresión.
        $this->actuandoComo('gafetes.ver', 'colaboradores.ver');
        $persona = Persona::factory()->create();
        $gafete = $persona->emitirGafete();

        $this->getJson("/api/personas/{$persona->id}/gafetes")
            ->assertOk()
            ->assertJsonMissingPath('data.0.qr_token');

        $this->getJson("/api/personas/{$persona->id}")
            ->assertOk()
            ->assertJsonPath('data.gafete.id', $gafete->id)
            ->assertJsonMissingPath('data.gafete.qr_token');

        $this->assertStringNotContainsString($gafete->qr_token, $this->getJson('/api/personas')->getContent());
    }

    // --- Impresión -----------------------------------------------------------

    public function test_la_impresion_entrega_el_token_y_los_datos_del_gafete(): void
    {
        $this->actuandoComo('gafetes.reimprimir');
        $departamento = Departamento::factory()->create(['nombre' => 'Producción']);
        $persona = Persona::factory()->sinSegundoApellido()->create([
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'numero_empleado' => '7001',
            'departamento_id' => $departamento->id,
        ]);
        $gafete = $persona->emitirGafete();

        $this->getJson("/api/gafetes/{$gafete->id}/impresion")
            ->assertOk()
            ->assertJsonPath('data.qr_token', $gafete->qr_token)
            ->assertJsonPath('data.persona.nombre_completo', 'Ana Solano')
            ->assertJsonPath('data.persona.numero_empleado', '7001')
            ->assertJsonPath('data.persona.departamento', 'Producción');
    }

    public function test_quien_emite_tambien_puede_imprimir(): void
    {
        // Se imprime justo después de emitir.
        $this->actuandoComo('gafetes.emitir');
        $gafete = Persona::factory()->create()->emitirGafete();

        $this->getJson("/api/gafetes/{$gafete->id}/impresion")->assertOk();
    }

    public function test_ver_el_historial_no_alcanza_para_imprimir(): void
    {
        $this->actuandoComo('gafetes.ver');
        $gafete = Persona::factory()->create()->emitirGafete();

        $this->getJson("/api/gafetes/{$gafete->id}/impresion")
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_no_se_imprime_un_gafete_reemplazado(): void
    {
        $this->actuandoComo('gafetes.reimprimir');
        $persona = Persona::factory()->create();
        $anterior = $persona->emitirGafete();
        $persona->emitirGafete();

        $this->getJson("/api/gafetes/{$anterior->id}/impresion")
            ->assertStatus(422)
            ->assertJsonMissingPath('data.qr_token');
    }

    public function test_no_se_imprime_el_gafete_de_una_persona_dada_de_baja(): void
    {
        $this->actuandoComo('gafetes.reimprimir');
        $persona = Persona::factory()->create();
        $gafete = $persona->emitirGafete();
        $persona->update(['estado' => 'INACTIVO']);

        $this->getJson("/api/gafetes/{$gafete->id}/impresion")->assertStatus(422);
    }

    // --- Permisos --------------------------------------------------------------

    public function test_el_historial_exige_gafetes_ver(): void
    {
        $this->actuandoComo('gafetes.emitir');
        $persona = Persona::factory()->create();

        $this->getJson("/api/personas/{$persona->id}/gafetes")->assertForbidden();
    }

    public function test_emitir_exige_gafetes_emitir(): void
    {
        $this->actuandoComo('gafetes.ver', 'gafetes.reimprimir');
        $persona = Persona::factory()->create();

        $this->postJson("/api/personas/{$persona->id}/gafetes")->assertForbidden();
        $this->assertSame(0, $persona->gafetes()->count());
    }

    public function test_los_endpoints_exigen_sesion(): void
    {
        $gafete = Persona::factory()->create()->emitirGafete();

        $this->getJson("/api/personas/{$gafete->persona_id}/gafetes")->assertUnauthorized();
        $this->postJson("/api/personas/{$gafete->persona_id}/gafetes")->assertUnauthorized();
        $this->getJson("/api/gafetes/{$gafete->id}/impresion")->assertUnauthorized();
    }

    public function test_el_catalogo_de_permisos_incluye_los_de_gafetes(): void
    {
        $this->seed(PermisoSeeder::class);

        foreach (['gafetes.ver', 'gafetes.emitir', 'gafetes.reimprimir'] as $clave) {
            $this->assertTrue(Permiso::where('clave', $clave)->exists(), "Falta {$clave}");
        }
    }

    public function test_el_modelo_oculta_el_token_al_serializar(): void
    {
        // Defensa adicional: un toArray() accidental tampoco lo filtra.
        $gafete = Gafete::factory()->create();

        $this->assertArrayNotHasKey('qr_token', $gafete->toArray());
    }
}
