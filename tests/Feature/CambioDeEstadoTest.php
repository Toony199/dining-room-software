<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Activar o desactivar tiene su propio permiso (`*.desactivar`), y en los roles además la regla
 * de §5.5. La edición no debe ser una segunda puerta para cambiar el estado.
 */
class CambioDeEstadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_un_rol_no_puede_desactivarlo(): void
    {
        // Antes, PUT /roles aceptaba `activo`: con solo `roles.editar` se desactivaba un rol, y
        // además se saltaba §5.5 aunque tuviera usuarios asignados.
        $this->actuandoComo('roles.editar');
        $rol = Rol::factory()->create(['nombre' => 'Cobrador']);
        User::factory()->create(['rol_id' => $rol->id]);

        $this->putJson("/api/roles/{$rol->id}", ['nombre' => 'Cobrador', 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', true);

        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'activo' => true]);
    }

    public function test_crear_un_rol_siempre_lo_deja_activo(): void
    {
        $this->actuandoComo('roles.crear');

        $this->postJson('/api/roles', ['nombre' => 'Nuevo', 'activo' => false])
            ->assertCreated()
            ->assertJsonPath('data.activo', true);
    }

    public function test_editar_un_departamento_no_basta_para_desactivarlo(): void
    {
        $this->actuandoComo('departamentos.editar');
        $departamento = Departamento::factory()->create(['nombre' => 'Producción']);

        $this->putJson("/api/departamentos/{$departamento->id}", ['nombre' => 'Producción', 'activo' => false])
            ->assertForbidden();

        $this->assertDatabaseHas('departamentos', ['id' => $departamento->id, 'activo' => true]);
    }

    public function test_con_el_permiso_de_desactivar_el_formulario_si_cambia_el_estado(): void
    {
        $this->actuandoComo('departamentos.editar', 'departamentos.desactivar');
        $departamento = Departamento::factory()->create(['nombre' => 'Producción']);

        $this->putJson("/api/departamentos/{$departamento->id}", ['nombre' => 'Producción', 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false);
    }

    public function test_reenviar_el_mismo_estado_no_exige_desactivar(): void
    {
        // El formulario manda el registro completo al corregir el nombre.
        $this->actuandoComo('departamentos.editar');
        $departamento = Departamento::factory()->create(['nombre' => 'Produccion']);

        $this->putJson("/api/departamentos/{$departamento->id}", ['nombre' => 'Producción', 'activo' => true])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Producción');
    }
}
