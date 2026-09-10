<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Las rutas exigen sesión y permiso (§5.6).
        $this->actuandoComo(
            'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar', 'colaboradores.desactivar',
        );
    }

    public function test_lista_personas_ordenadas_por_apellidos(): void
    {
        Persona::factory()->create(['primer_apellido' => 'Zavala', 'nombre' => 'Ana']);
        Persona::factory()->create(['primer_apellido' => 'Alvarez', 'nombre' => 'Beto']);

        // +1 por la persona que crea la sesión (ver TestCase::actuandoComo), cuyo apellido
        // ordena al final a propósito.
        $this->getJson('/api/personas')
            ->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.primer_apellido', 'Alvarez');
    }

    public function test_el_index_expone_solo_los_campos_del_resource(): void
    {
        Persona::factory()->create();

        // El contrato lo define PersonasResource, no las columnas de la tabla.
        $this->getJson('/api/personas')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'numero_empleado', 'nombre', 'primer_apellido', 'segundo_apellido',
                    'nombre_completo', 'departamento_id', 'departamento' => ['id', 'nombre', 'activo'],
                    'foto_path', 'estado', 'created_at', 'updated_at',
                ]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_nombre_completo_omite_el_segundo_apellido_ausente(): void
    {
        Persona::factory()->sinSegundoApellido()->create([
            'nombre' => 'Ana',
            'primer_apellido' => 'Perez',
        ]);

        $this->getJson('/api/personas')
            ->assertOk()
            ->assertJsonPath('data.0.nombre_completo', 'Ana Perez');
    }

    public function test_el_index_pagina_los_resultados(): void
    {
        Persona::factory()->count(20)->create();

        $this->getJson('/api/personas?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 20 + self::REGISTROS_DE_SESION)
            ->assertJsonPath('meta.last_page', 5)
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_per_page_se_acota_a_100(): void
    {
        Persona::factory()->count(3)->create();

        $this->getJson('/api/personas?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_filtra_por_estado(): void
    {
        Persona::factory()->create(['primer_apellido' => 'Vigente']);
        Persona::factory()->inactiva()->create(['primer_apellido' => 'DeBaja']);

        // Los flujos operativos (fichas, gafetes) deben pasar estado=ACTIVO: una persona
        // inactiva no opera (§3.3).
        // La persona de la sesión también está activa, de ahí el +1.
        $this->getJson('/api/personas?estado=ACTIVO')
            ->assertOk()
            ->assertJsonCount(1 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.primer_apellido', 'Vigente');

        $this->getJson('/api/personas?estado=INACTIVO')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.primer_apellido', 'DeBaja');

        // Sin el parámetro, administración ve ambas.
        $this->getJson('/api/personas')
            ->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data');
    }

    public function test_busca_por_nombre_apellido_o_numero_de_empleado(): void
    {
        // Se fija el segundo apellido: el de la factory es aleatorio y puede contener el
        // término buscado, lo que volvía intermitente esta prueba.
        Persona::factory()->sinSegundoApellido()->create([
            'nombre' => 'Guadalupe', 'primer_apellido' => 'Ramirez', 'numero_empleado' => '4821',
        ]);
        Persona::factory()->sinSegundoApellido()->create([
            'nombre' => 'Beto', 'primer_apellido' => 'Solis', 'numero_empleado' => '9007',
        ]);

        $this->getJson('/api/personas?q=guada')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/personas?q=rami')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/personas?q=4821')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Guadalupe');
    }

    public function test_la_busqueda_no_anula_el_filtro_de_estado(): void
    {
        Persona::factory()->create(['primer_apellido' => 'Perez', 'nombre' => 'Activa']);
        Persona::factory()->inactiva()->create(['primer_apellido' => 'Perez', 'nombre' => 'Inactiva']);

        // Los orWhere de la búsqueda van agrupados: si se aplicaran sueltos, este caso
        // devolvería también a la persona inactiva.
        $this->getJson('/api/personas?q=Perez&estado=ACTIVO')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Activa');
    }

    public function test_filtra_por_departamento(): void
    {
        $produccion = Departamento::factory()->create(['nombre' => 'Producción']);
        Persona::factory()->create(['departamento_id' => $produccion->id, 'nombre' => 'Adscrita']);
        Persona::factory()->create(['nombre' => 'Ajena']);

        $this->getJson("/api/personas?departamento_id={$produccion->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Adscrita');
    }

    public function test_crea_una_persona_activa(): void
    {
        $departamento = Departamento::factory()->create();

        $response = $this->postJson('/api/personas', [
            'numero_empleado' => '1234',
            'nombre' => 'Juan',
            'primer_apellido' => 'Pérez',
            'segundo_apellido' => 'Ruiz',
            'departamento_id' => $departamento->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.numero_empleado', '1234')
            ->assertJsonPath('data.nombre_completo', 'Juan Pérez Ruiz')
            ->assertJsonPath('data.estado', 'ACTIVO')
            ->assertJsonPath('data.departamento.id', $departamento->id);

        $this->assertDatabaseHas('personas', ['numero_empleado' => '1234', 'estado' => 'ACTIVO']);
    }

    public function test_acepta_una_persona_con_un_solo_apellido(): void
    {
        $response = $this->postJson('/api/personas', [
            'numero_empleado' => '55',
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'departamento_id' => Departamento::factory()->create()->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.segundo_apellido', null);
    }

    public function test_el_alta_no_permite_forzar_el_estado_ni_la_foto(): void
    {
        // El estado se cambia por desactivar/activar (§3.3) y la ruta de la foto la escribirá
        // su propio endpoint de carga (§3.1): ninguno se toma del formulario.
        $response = $this->postJson('/api/personas', [
            'numero_empleado' => '77',
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'departamento_id' => Departamento::factory()->create()->id,
            'estado' => 'INACTIVO',
            'foto_path' => '../../evil.php',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.estado', 'ACTIVO')
            ->assertJsonPath('data.foto_path', null);
    }

    public function test_numero_de_empleado_es_obligatorio(): void
    {
        $this->postJson('/api/personas', [
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'departamento_id' => Departamento::factory()->create()->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('numero_empleado');
    }

    public function test_numero_de_empleado_no_se_duplica_ni_se_reasigna(): void
    {
        // Aunque la persona esté dada de baja, su número queda reservado para siempre (§3.2).
        Persona::factory()->inactiva()->create(['numero_empleado' => '1234']);

        $this->postJson('/api/personas', [
            'numero_empleado' => '1234',
            'nombre' => 'Otra',
            'primer_apellido' => 'Persona',
            'departamento_id' => Departamento::factory()->create()->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('numero_empleado');
    }

    public function test_el_departamento_debe_existir(): void
    {
        $this->postJson('/api/personas', [
            'numero_empleado' => '99',
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'departamento_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrorFor('departamento_id');
    }

    public function test_no_se_puede_dar_de_alta_en_un_departamento_inactivo(): void
    {
        // Un catálogo dado de baja no debe ofrecerse para asignar colaboradores nuevos (§3.4).
        $inactivo = Departamento::factory()->inactivo()->create();

        $this->postJson('/api/personas', [
            'numero_empleado' => '99',
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
            'departamento_id' => $inactivo->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('departamento_id');
    }

    public function test_muestra_una_persona(): void
    {
        $persona = Persona::factory()->create(['nombre' => 'Ana']);

        $this->getJson("/api/personas/{$persona->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $persona->id)
            ->assertJsonPath('data.nombre', 'Ana')
            ->assertJsonPath('data.departamento.id', $persona->departamento_id);
    }

    public function test_mostrar_una_persona_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/personas/999999')->assertNotFound();
    }

    public function test_actualiza_una_persona_y_su_departamento(): void
    {
        $persona = Persona::factory()->create(['numero_empleado' => '1234', 'nombre' => 'Ana']);
        $mantenimiento = Departamento::factory()->create(['nombre' => 'Mantenimiento']);

        $this->putJson("/api/personas/{$persona->id}", [
            'numero_empleado' => '1234',
            'nombre' => 'Ana María',
            'primer_apellido' => $persona->primer_apellido,
            'segundo_apellido' => $persona->segundo_apellido,
            'departamento_id' => $mantenimiento->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Ana María')
            ->assertJsonPath('data.departamento.nombre', 'Mantenimiento');

        $this->assertDatabaseHas('personas', [
            'id' => $persona->id,
            'departamento_id' => $mantenimiento->id,
        ]);
    }

    public function test_el_numero_de_empleado_es_inmutable(): void
    {
        $persona = Persona::factory()->create(['numero_empleado' => '1234']);

        $this->putJson("/api/personas/{$persona->id}", [
            'numero_empleado' => '5678',
            'nombre' => $persona->nombre,
            'primer_apellido' => $persona->primer_apellido,
            'departamento_id' => $persona->departamento_id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('numero_empleado');

        $this->assertDatabaseHas('personas', ['id' => $persona->id, 'numero_empleado' => '1234']);
    }

    public function test_actualizar_permite_reenviar_el_mismo_numero_de_empleado(): void
    {
        // El formulario manda el registro completo; el campo inmutable no debe estorbarle.
        $persona = Persona::factory()->create(['numero_empleado' => '1234']);

        $this->putJson("/api/personas/{$persona->id}", [
            'numero_empleado' => '1234',
            'nombre' => 'Renombrada',
            'primer_apellido' => $persona->primer_apellido,
            'departamento_id' => $persona->departamento_id,
        ])->assertOk()->assertJsonPath('data.nombre', 'Renombrada');
    }

    public function test_actualizar_conserva_un_departamento_desactivado_despues(): void
    {
        // El departamento se dio de baja después de la adscripción: editar el nombre de la
        // persona no debe quedar bloqueado por eso (§3.4).
        $departamento = Departamento::factory()->create();
        $persona = Persona::factory()->create(['departamento_id' => $departamento->id]);
        $departamento->update(['activo' => false]);

        $this->putJson("/api/personas/{$persona->id}", [
            'numero_empleado' => $persona->numero_empleado,
            'nombre' => 'Corregida',
            'primer_apellido' => $persona->primer_apellido,
            'departamento_id' => $departamento->id,
        ])->assertOk()->assertJsonPath('data.nombre', 'Corregida');
    }

    public function test_desactivar_es_baja_logica_no_borrado_fisico(): void
    {
        $persona = Persona::factory()->create();

        $this->patchJson("/api/personas/{$persona->id}/desactivar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'INACTIVO');

        // El registro SIGUE existiendo (§3.3): sus operaciones históricas quedan para auditoría.
        $this->assertDatabaseHas('personas', ['id' => $persona->id, 'estado' => 'INACTIVO']);
    }

    public function test_activar_reactiva_una_persona(): void
    {
        $persona = Persona::factory()->inactiva()->create();

        $this->patchJson("/api/personas/{$persona->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'ACTIVO');
    }

    public function test_no_existe_borrado_fisico_de_personas(): void
    {
        $persona = Persona::factory()->create();

        // §3.3: la persona no debe eliminarse físicamente, así que la ruta no existe.
        $this->deleteJson("/api/personas/{$persona->id}")->assertStatus(405);
        $this->assertDatabaseHas('personas', ['id' => $persona->id]);
    }
}
