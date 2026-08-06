<?php

namespace Tests\Feature;

use App\Models\Departamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_departamentos_ordenados_por_nombre(): void
    {
        Departamento::create(['nombre' => 'Producción']);
        Departamento::create(['nombre' => 'Almacén']);

        $response = $this->getJson('/api/departamentos');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.nombre', 'Almacén'); // orden alfabético
    }

    public function test_crea_un_departamento(): void
    {
        $response = $this->postJson('/api/departamentos', ['nombre' => 'Mantenimiento']);

        $response->assertCreated()
            ->assertJsonPath('nombre', 'Mantenimiento')
            ->assertJsonPath('activo', true);

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
        Departamento::create(['nombre' => 'Calidad']);

        $response = $this->postJson('/api/departamentos', ['nombre' => 'Calidad']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('nombre');
    }

    public function test_actualiza_un_departamento(): void
    {
        $depto = Departamento::create(['nombre' => 'Sistemas']);

        $response = $this->putJson("/api/departamentos/{$depto->id}", [
            'nombre' => 'Tecnologías de la Información',
        ]);

        $response->assertOk()->assertJsonPath('nombre', 'Tecnologías de la Información');
        $this->assertDatabaseHas('departamentos', ['id' => $depto->id, 'nombre' => 'Tecnologías de la Información']);
    }

    public function test_actualizar_permite_conservar_el_mismo_nombre(): void
    {
        $depto = Departamento::create(['nombre' => 'Compras']);

        // El unique debe ignorar el propio registro.
        $response = $this->putJson("/api/departamentos/{$depto->id}", [
            'nombre' => 'Compras',
            'activo' => false,
        ]);

        $response->assertOk()->assertJsonPath('activo', false);
    }

    public function test_desactivar_es_baja_logica_no_borrado_fisico(): void
    {
        $depto = Departamento::create(['nombre' => 'Logística']);

        $response = $this->patchJson("/api/departamentos/{$depto->id}/desactivar");

        $response->assertOk()->assertJsonPath('activo', false);
        // El registro SIGUE existiendo (RN-07): baja lógica, nunca DELETE físico.
        $this->assertDatabaseHas('departamentos', ['id' => $depto->id, 'activo' => false]);
    }

    public function test_activar_reactiva_un_departamento(): void
    {
        $depto = Departamento::create(['nombre' => 'Recursos Humanos', 'activo' => false]);

        $response = $this->patchJson("/api/departamentos/{$depto->id}/activar");

        $response->assertOk()->assertJsonPath('activo', true);
    }
}
