<?php

namespace Tests\Feature;

use App\Models\Departamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Las rutas exigen sesión y permiso (§5.6). Se conceden solo los del módulo
        // que esta clase ejercita.
        $this->actuandoComo('departamentos.ver', 'departamentos.crear', 'departamentos.editar', 'departamentos.desactivar');
    }

    public function test_lista_departamentos_ordenados_por_nombre(): void
    {
        Departamento::factory()->create(['nombre' => 'Producción']);
        Departamento::factory()->create(['nombre' => 'Almacén']);

        $response = $this->getJson('/api/departamentos');

        // +1 por el departamento que crea la sesión (ver TestCase::actuandoComo).
        $response->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.nombre', 'Almacén'); // orden alfabético
    }

    public function test_el_index_expone_solo_los_campos_del_resource(): void
    {
        Departamento::factory()->create();

        $response = $this->getJson('/api/departamentos');

        // El contrato lo define DepartamentoResource, no las columnas de la tabla.
        $response->assertOk()->assertJsonStructure([
            'data' => [['id', 'nombre', 'activo', 'created_at', 'updated_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_el_index_pagina_los_resultados(): void
    {
        Departamento::factory()->count(20)->create();

        $response = $this->getJson('/api/departamentos?per_page=5');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 20 + self::REGISTROS_DE_SESION)
            ->assertJsonPath('meta.last_page', 5)
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_per_page_se_acota_a_100(): void
    {
        Departamento::factory()->count(3)->create();

        $response = $this->getJson('/api/departamentos?per_page=5000');

        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_filtra_por_activo(): void
    {
        Departamento::factory()->create(['nombre' => 'Vigente']);
        Departamento::factory()->inactivo()->create(['nombre' => 'De baja']);

        // ?activo=1 es lo que deben usar los selects de formulario (§3.4): un departamento
        // dado de baja no debe ofrecerse para asignar colaboradores nuevos.
        // El departamento de la sesión también está activo, de ahí el +1.
        $this->getJson('/api/departamentos?activo=1')
            ->assertOk()
            ->assertJsonCount(1 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.nombre', 'Vigente');

        $this->getJson('/api/departamentos?activo=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'De baja');

        // Sin el parámetro, administración ve ambos.
        $this->getJson('/api/departamentos')
            ->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data');
    }

    public function test_busca_por_nombre_parcial(): void
    {
        Departamento::factory()->create(['nombre' => 'Recursos Humanos']);
        Departamento::factory()->create(['nombre' => 'Producción']);

        $this->getJson('/api/departamentos?q=human')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Recursos Humanos');
    }

    public function test_crea_un_departamento(): void
    {
        $response = $this->postJson('/api/departamentos', ['nombre' => 'Mantenimiento']);

        $response->assertCreated()
            ->assertJsonPath('data.nombre', 'Mantenimiento')
            ->assertJsonPath('data.activo', true);

        $this->assertDatabaseHas('departamentos', [
            'nombre' => 'Mantenimiento',
            'activo' => true,
        ]);
    }

    public function test_nombre_es_obligatorio(): void
    {
        $response = $this->postJson('/api/departamentos', ['nombre' => '']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
    }

    public function test_nombre_no_se_duplica(): void
    {
        Departamento::factory()->create(['nombre' => 'Calidad']);

        $response = $this->postJson('/api/departamentos', ['nombre' => 'Calidad']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
    }

    public function test_muestra_un_departamento(): void
    {
        $depto = Departamento::factory()->create(['nombre' => 'Almacén']);

        $this->getJson("/api/departamentos/{$depto->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $depto->id)
            ->assertJsonPath('data.nombre', 'Almacén');
    }

    public function test_mostrar_un_departamento_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/departamentos/999')->assertNotFound();
    }

    public function test_actualiza_un_departamento(): void
    {
        $depto = Departamento::factory()->create(['nombre' => 'Sistemas']);

        $response = $this->putJson("/api/departamentos/{$depto->id}", [
            'nombre' => 'Tecnologías de la Información',
        ]);

        $response->assertOk()->assertJsonPath('data.nombre', 'Tecnologías de la Información');
        $this->assertDatabaseHas('departamentos', ['id' => $depto->id, 'nombre' => 'Tecnologías de la Información']);
    }

    public function test_actualizar_permite_conservar_el_mismo_nombre(): void
    {
        $depto = Departamento::factory()->create(['nombre' => 'Compras']);

        // El unique debe ignorar el propio registro.
        $response = $this->putJson("/api/departamentos/{$depto->id}", [
            'nombre' => 'Compras',
            'activo' => false,
        ]);

        $response->assertOk()->assertJsonPath('data.activo', false);
    }

    public function test_desactivar_es_baja_logica_no_borrado_fisico(): void
    {
        $depto = Departamento::factory()->create(['nombre' => 'Logística']);

        $response = $this->patchJson("/api/departamentos/{$depto->id}/desactivar");

        $response->assertOk()->assertJsonPath('data.activo', false);
        // El registro SIGUE existiendo (RN-07): baja lógica, nunca DELETE físico.
        $this->assertDatabaseHas('departamentos', ['id' => $depto->id, 'activo' => false]);
    }

    public function test_activar_reactiva_un_departamento(): void
    {
        $depto = Departamento::factory()->inactivo()->create(['nombre' => 'Recursos Humanos']);

        $response = $this->patchJson("/api/departamentos/{$depto->id}/activar");

        $response->assertOk()->assertJsonPath('data.activo', true);
    }
}
