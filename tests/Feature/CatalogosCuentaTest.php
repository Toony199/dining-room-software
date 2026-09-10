<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Catálogos del formulario de cuentas.
 *
 * Existen para que administrar cuentas no obligue a tener `roles.ver` ni `colaboradores.ver`.
 * Antes de ellos, un rol con `usuarios.crear` pero sin `roles.ver` veía vacío el select de rol
 * y no podía crear ninguna cuenta, aunque su rol decía que sí.
 */
class CatalogosCuentaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function permisosDeCuentas(): array
    {
        return [
            'ver' => ['usuarios.ver'],
            'crear' => ['usuarios.crear'],
            'editar' => ['usuarios.editar'],
        ];
    }

    public function test_roles_asignables_no_exige_roles_ver(): void
    {
        $this->actuandoComo('usuarios.crear');
        Rol::factory()->create(['nombre' => 'Cobrador']);
        Rol::factory()->inactivo()->create(['nombre' => 'Obsoleto']);

        $respuesta = $this->getJson('/api/usuarios/roles-asignables')->assertOk();

        // Solo los activos: un rol dado de baja no debe ofrecerse (§5.5).
        $nombres = collect($respuesta->json('data'))->pluck('nombre');
        $this->assertContains('Cobrador', $nombres);
        $this->assertNotContains('Obsoleto', $nombres);
    }

    public function test_roles_asignables_entrega_solo_lo_necesario_para_elegir(): void
    {
        // Asignar un rol no es lo mismo que ver cómo está configurado.
        $this->actuandoComo('usuarios.crear');

        $this->getJson('/api/usuarios/roles-asignables')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'nombre']]])
            ->assertJsonMissingPath('data.0.descripcion')
            ->assertJsonMissingPath('data.0.permisos_count')
            ->assertJsonMissingPath('data.0.usuarios_count')
            ->assertJsonMissingPath('data.0.permisos');
    }

    #[DataProvider('permisosDeCuentas')]
    public function test_roles_asignables_acepta_cualquier_permiso_de_cuentas(string $permiso): void
    {
        // `ver` también: el listado de cuentas lo usa para su filtro por rol.
        $this->actuandoComo($permiso);

        $this->getJson('/api/usuarios/roles-asignables')->assertOk();
    }

    public function test_roles_asignables_exige_algun_permiso_de_cuentas(): void
    {
        $this->actuandoComo('colaboradores.ver');

        $this->getJson('/api/usuarios/roles-asignables')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_los_catalogos_exigen_sesion(): void
    {
        $this->getJson('/api/usuarios/roles-asignables')->assertUnauthorized();
        $this->getJson('/api/usuarios/personas-disponibles')->assertUnauthorized();
    }

    public function test_la_configuracion_de_los_roles_sigue_exigiendo_roles_ver(): void
    {
        // El catálogo nuevo no es una puerta trasera a /api/roles.
        $this->actuandoComo('usuarios.ver', 'usuarios.crear', 'usuarios.editar');
        $rol = Rol::factory()->create();

        $this->getJson('/api/roles')->assertForbidden();
        $this->getJson("/api/roles/{$rol->id}")->assertForbidden();
    }

    public function test_personas_disponibles_no_exige_colaboradores_ver(): void
    {
        $this->actuandoComo('usuarios.crear');

        $libre = Persona::factory()->create();
        $conCuenta = Persona::factory()->create();
        User::factory()->create(['persona_id' => $conCuenta->id]);
        $deBaja = Persona::factory()->inactiva()->create();

        $ids = collect($this->getJson('/api/usuarios/personas-disponibles')->assertOk()->json('data'))->pluck('id');

        // Activas (§3.3) y sin cuenta todavía (§3.1).
        $this->assertContains($libre->id, $ids);
        $this->assertNotContains($conCuenta->id, $ids);
        $this->assertNotContains($deBaja->id, $ids);
    }

    public function test_personas_disponibles_entrega_solo_lo_necesario_para_elegir(): void
    {
        $this->actuandoComo('usuarios.crear');
        Persona::factory()->create();

        $this->getJson('/api/usuarios/personas-disponibles')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'numero_empleado', 'nombre_completo']]])
            ->assertJsonMissingPath('data.0.departamento')
            ->assertJsonMissingPath('data.0.estado')
            ->assertJsonMissingPath('data.0.cuenta');
    }

    public function test_personas_disponibles_exige_usuarios_crear(): void
    {
        // Solo sirve para el alta: la edición de una cuenta nunca cambia de persona.
        $this->actuandoComo('usuarios.ver', 'usuarios.editar');

        $this->getJson('/api/usuarios/personas-disponibles')->assertForbidden();
    }

    public function test_un_rol_sin_roles_ver_ni_colaboradores_ver_puede_crear_cuentas(): void
    {
        // El escenario que motivó los catálogos, de punta a punta: un rol tipo "Recursos
        // Humanos" que administra cuentas sin ver la configuración de roles ni el personal.
        $this->actuandoComo('usuarios.ver', 'usuarios.crear', 'usuarios.editar');
        Rol::factory()->create(['nombre' => 'Cobrador']);
        $persona = Persona::factory()->create();

        $rol = collect($this->getJson('/api/usuarios/roles-asignables')->json('data'))
            ->firstWhere('nombre', 'Cobrador');
        $disponible = collect($this->getJson('/api/usuarios/personas-disponibles')->json('data'))
            ->firstWhere('id', $persona->id);

        $this->assertNotNull($rol);
        $this->assertNotNull($disponible);

        $this->postJson('/api/usuarios', [
            'persona_id' => $disponible['id'],
            'email' => 'nueva.cuenta@arod.test',
            'password' => 'contrasena-larga',
            'password_confirmation' => 'contrasena-larga',
            'rol_id' => $rol['id'],
        ])->assertCreated()->assertJsonPath('data.rol.nombre', 'Cobrador');
    }
}
