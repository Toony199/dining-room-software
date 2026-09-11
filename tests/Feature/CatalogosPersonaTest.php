<?php

namespace Tests\Feature;

use App\Models\Departamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Catálogo de departamentos del formulario de personas.
 *
 * Existe para que dar de alta personal no obligue a tener `departamentos.ver`. Antes, un rol
 * con `colaboradores.crear` sin ese permiso veía vacío el select de departamento y no podía dar
 * de alta a nadie; concederlo, a cambio, le abría el módulo de departamentos completo.
 */
class CatalogosPersonaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function permisosDeColaboradores(): array
    {
        return [
            'ver' => ['colaboradores.ver'],
            'crear' => ['colaboradores.crear'],
            'editar' => ['colaboradores.editar'],
        ];
    }

    public function test_no_exige_departamentos_ver(): void
    {
        $this->actuandoComo('colaboradores.crear');
        Departamento::factory()->create(['nombre' => 'Producción']);
        Departamento::factory()->inactivo()->create(['nombre' => 'Obsoleto']);

        $nombres = collect($this->getJson('/api/personas/departamentos-asignables')->assertOk()->json('data'))
            ->pluck('nombre');

        // Solo los activos: un catálogo dado de baja no se ofrece para adscribir (§3.4).
        $this->assertContains('Producción', $nombres);
        $this->assertNotContains('Obsoleto', $nombres);
    }

    public function test_entrega_solo_lo_necesario_para_elegir(): void
    {
        $this->actuandoComo('colaboradores.crear');

        $this->getJson('/api/personas/departamentos-asignables')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'nombre']]])
            ->assertJsonMissingPath('data.0.activo')
            ->assertJsonMissingPath('data.0.created_at')
            ->assertJsonMissingPath('data.0.personas_count');
    }

    #[DataProvider('permisosDeColaboradores')]
    public function test_acepta_cualquier_permiso_de_colaboradores(string $permiso): void
    {
        // `ver` también: el listado de personas lo usa para su filtro por departamento.
        $this->actuandoComo($permiso);

        $this->getJson('/api/personas/departamentos-asignables')->assertOk();
    }

    public function test_exige_algun_permiso_de_colaboradores(): void
    {
        $this->actuandoComo('usuarios.ver');

        $this->getJson('/api/personas/departamentos-asignables')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_exige_sesion(): void
    {
        $this->getJson('/api/personas/departamentos-asignables')->assertUnauthorized();
    }

    public function test_el_modulo_de_departamentos_sigue_exigiendo_departamentos_ver(): void
    {
        // El catálogo no es una puerta trasera al módulo.
        $this->actuandoComo('colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar');
        $departamento = Departamento::factory()->create();

        $this->getJson('/api/departamentos')->assertForbidden();
        $this->getJson("/api/departamentos/{$departamento->id}")->assertForbidden();
    }

    public function test_un_rol_sin_departamentos_ver_puede_dar_de_alta_personal(): void
    {
        // El escenario del reporte, de punta a punta.
        $this->actuandoComo('colaboradores.crear');
        Departamento::factory()->create(['nombre' => 'Producción']);

        $departamento = collect($this->getJson('/api/personas/departamentos-asignables')->json('data'))
            ->firstWhere('nombre', 'Producción');

        $this->postJson('/api/personas', [
            'numero_empleado' => '5150',
            'nombre' => 'Nueva',
            'primer_apellido' => 'Alta',
            'departamento_id' => $departamento['id'],
        ])->assertCreated()->assertJsonPath('data.departamento.nombre', 'Producción');
    }
}
