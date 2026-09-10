<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\PermisoSeeder;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Las rutas exigen sesión y permiso (§5.6). Se conceden solo los del módulo
        // que esta clase ejercita.
        $this->actuandoComo('roles.ver', 'roles.crear', 'roles.editar', 'roles.desactivar');
    }

    public function test_lista_roles_ordenados_por_nombre(): void
    {
        Rol::factory()->create(['nombre' => 'Supervisor']);
        Rol::factory()->create(['nombre' => 'Cobrador']);

        // +1 por el rol que crea la sesión (ver TestCase::actuandoComo), que ordena al final.
        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.nombre', 'Cobrador');
    }

    public function test_el_index_expone_solo_los_campos_del_resource(): void
    {
        Rol::factory()->create();

        // El contrato lo define RolResource, no las columnas de la tabla.
        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'nombre', 'descripcion', 'activo',
                    'permisos_count', 'usuarios_count', 'created_at', 'updated_at',
                ]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_el_index_no_carga_los_permisos_de_cada_rol(): void
    {
        $rol = Rol::factory()->create(['nombre' => 'Con permisos']);
        $rol->permisos()->sync(Permiso::factory()->count(3)->create()->pluck('id'));

        // El index solo necesita el conteo: cargar el pivote de cada fila sería gratuito
        // en 5 roles y caro en 50.
        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'Con permisos')
            ->assertJsonPath('data.0.permisos_count', 3)
            ->assertJsonMissingPath('data.0.permisos');
    }

    public function test_el_index_pagina_y_acota_per_page_a_100(): void
    {
        Rol::factory()->count(15)->create();

        $this->getJson('/api/roles?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 15 + self::REGISTROS_DE_SESION)
            ->assertJsonPath('meta.last_page', 4);

        $this->getJson('/api/roles?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_filtra_por_activo_y_busca_por_nombre(): void
    {
        Rol::factory()->create(['nombre' => 'Cobrador']);
        Rol::factory()->inactivo()->create(['nombre' => 'Obsoleto']);

        // El formulario que asigna rol a una cuenta debe pasar activo=1: un rol desactivado
        // no debe ofrecerse.
        // El rol de la sesión también está activo, de ahí el +1.
        $this->getJson('/api/roles?activo=1')
            ->assertOk()
            ->assertJsonCount(1 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.nombre', 'Cobrador');

        $this->getJson('/api/roles?q=obsol')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Obsoleto');
    }

    public function test_crea_un_rol_con_sus_permisos(): void
    {
        $permisos = Permiso::factory()->count(2)->create();

        $response = $this->postJson('/api/roles', [
            'nombre' => 'Cobrador',
            'descripcion' => 'Confirma pagos en caja.',
            'permisos' => $permisos->pluck('id')->all(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.nombre', 'Cobrador')
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.permisos_count', 2)
            ->assertJsonCount(2, 'data.permisos');

        // Se cuenta el pivote de ESTE rol, no la tabla entera: el rol de la sesión tiene los
        // suyos y contarlos todos haría que la aserción dependiera del helper.
        $this->assertSame(2, Rol::where('nombre', 'Cobrador')->firstOrFail()->permisos()->count());
    }

    public function test_crea_un_rol_sin_permisos(): void
    {
        $this->postJson('/api/roles', ['nombre' => 'Vacío'])
            ->assertCreated()
            ->assertJsonPath('data.permisos_count', 0);
    }

    public function test_el_nombre_es_obligatorio_y_no_se_duplica(): void
    {
        $this->postJson('/api/roles', ['nombre' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('nombre');

        Rol::factory()->create(['nombre' => 'Cobrador']);

        $this->postJson('/api/roles', ['nombre' => 'Cobrador'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('nombre');
    }

    public function test_rechaza_permisos_inexistentes(): void
    {
        $this->postJson('/api/roles', ['nombre' => 'Raro', 'permisos' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('permisos.0');

        $this->assertDatabaseMissing('roles', ['nombre' => 'Raro']);
    }

    public function test_muestra_un_rol_con_sus_permisos(): void
    {
        $rol = Rol::factory()->create(['nombre' => 'Supervisor']);
        $rol->permisos()->sync(Permiso::factory()->count(2)->create()->pluck('id'));

        $this->getJson("/api/roles/{$rol->id}")
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Supervisor')
            ->assertJsonCount(2, 'data.permisos')
            ->assertJsonStructure(['data' => ['permisos' => [['id', 'clave', 'descripcion', 'modulo']]]]);
    }

    public function test_mostrar_un_rol_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/roles/999999')->assertNotFound();
    }

    public function test_actualizar_sincroniza_los_permisos(): void
    {
        $rol = Rol::factory()->create(['nombre' => 'Cobrador']);
        $viejos = Permiso::factory()->count(2)->create();
        $nuevo = Permiso::factory()->create();
        $rol->permisos()->sync($viejos->pluck('id'));

        // `permisos` es la lista completa que debe quedar, no un incremento.
        $this->putJson("/api/roles/{$rol->id}", [
            'nombre' => 'Cobrador',
            'permisos' => [$nuevo->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.permisos_count', 1)
            ->assertJsonPath('data.permisos.0.id', $nuevo->id);

        $this->assertSame(1, $rol->permisos()->count());
    }

    public function test_actualizar_sin_mandar_permisos_deja_el_pivote_intacto(): void
    {
        // Editar solo el nombre no debe vaciarle los permisos al rol.
        $rol = Rol::factory()->create(['nombre' => 'Cobrador']);
        $rol->permisos()->sync(Permiso::factory()->count(3)->create()->pluck('id'));

        $this->putJson("/api/roles/{$rol->id}", ['nombre' => 'Cajero'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Cajero')
            ->assertJsonPath('data.permisos_count', 3);
    }

    public function test_actualizar_con_permisos_vacios_deja_el_rol_sin_permisos(): void
    {
        $rol = Rol::factory()->create();
        $rol->permisos()->sync(Permiso::factory()->count(2)->create()->pluck('id'));

        $this->putJson("/api/roles/{$rol->id}", ['nombre' => $rol->nombre, 'permisos' => []])
            ->assertOk()
            ->assertJsonPath('data.permisos_count', 0);

        $this->assertSame(0, $rol->permisos()->count());
    }

    public function test_actualizar_permite_conservar_el_mismo_nombre(): void
    {
        $rol = Rol::factory()->create(['nombre' => 'Compras']);

        // El unique debe ignorar el propio registro.
        $this->putJson("/api/roles/{$rol->id}", ['nombre' => 'Compras', 'descripcion' => 'Otra.'])
            ->assertOk()
            ->assertJsonPath('data.descripcion', 'Otra.');
    }

    public function test_los_cambios_en_un_rol_alcanzan_de_inmediato_a_sus_usuarios(): void
    {
        // §5.4: los permisos no se copian a la cuenta, se consultan contra el rol.
        $rol = Rol::factory()->create();
        $usuario = User::factory()->create(['rol_id' => $rol->id]);
        $permiso = Permiso::factory()->create(['clave' => 'pagos.cancelar']);

        $this->assertFalse($usuario->tienePermiso('pagos.cancelar'));

        $this->putJson("/api/roles/{$rol->id}", [
            'nombre' => $rol->nombre,
            'permisos' => [$permiso->id],
        ])->assertOk();

        $this->assertTrue($usuario->fresh()->tienePermiso('pagos.cancelar'));
    }

    public function test_no_se_puede_desactivar_un_rol_con_usuarios_asignados(): void
    {
        // §5.5: primero hay que reasignar a los usuarios.
        $rol = Rol::factory()->create();
        User::factory()->count(2)->create(['rol_id' => $rol->id]);

        $this->patchJson("/api/roles/{$rol->id}/desactivar")
            ->assertStatus(422)
            ->assertJsonPath('usuarios_count', 2);

        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'activo' => true]);
    }

    public function test_desactiva_un_rol_sin_usuarios(): void
    {
        $rol = Rol::factory()->create();

        $this->patchJson("/api/roles/{$rol->id}/desactivar")
            ->assertOk()
            ->assertJsonPath('data.activo', false);

        // Baja lógica: el rol sigue existiendo para conservar el historial.
        $this->assertDatabaseHas('roles', ['id' => $rol->id, 'activo' => false]);
    }

    public function test_desactivar_es_posible_tras_reasignar_a_los_usuarios(): void
    {
        $rol = Rol::factory()->create();
        $otro = Rol::factory()->create();
        $usuario = User::factory()->create(['rol_id' => $rol->id]);

        $this->patchJson("/api/roles/{$rol->id}/desactivar")->assertStatus(422);

        $usuario->update(['rol_id' => $otro->id]);

        $this->patchJson("/api/roles/{$rol->id}/desactivar")->assertOk();
    }

    public function test_activar_reactiva_un_rol(): void
    {
        $rol = Rol::factory()->inactivo()->create();

        $this->patchJson("/api/roles/{$rol->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.activo', true);
    }

    public function test_no_existe_borrado_fisico_de_roles(): void
    {
        $rol = Rol::factory()->create();

        // §5.5: los roles se desactivan, no se borran.
        $this->deleteJson("/api/roles/{$rol->id}")->assertStatus(405);
        $this->assertDatabaseHas('roles', ['id' => $rol->id]);
    }

    public function test_el_catalogo_de_permisos_llega_agrupable_por_modulo(): void
    {
        $this->seed(PermisoSeeder::class);

        $response = $this->getJson('/api/permisos');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'clave', 'descripcion', 'modulo']]]);

        // Se devuelve entero y sin paginar: la pantalla de asignación lo necesita completo.
        $claves = collect($response->json('data'))->pluck('clave');
        $this->assertContains('pagos.confirmar', $claves);
        $this->assertContains('periodos.reabrir', $claves);
        $this->assertSame($claves->sort()->values()->all(), $claves->sort()->values()->all());
    }

    public function test_el_catalogo_de_permisos_es_de_solo_lectura(): void
    {
        // Los permisos los define el código conforme se agregan módulos, no el usuario final.
        $this->postJson('/api/permisos', ['clave' => 'hack.todo', 'modulo' => 'hack'])
            ->assertStatus(405);
    }

    public function test_el_seeder_de_permisos_es_idempotente(): void
    {
        $this->seed(PermisoSeeder::class);
        $total = Permiso::count();

        $this->seed(PermisoSeeder::class);

        // Se vuelve a correr en cada despliegue que agregue permisos de un módulo nuevo.
        $this->assertSame($total, Permiso::count());
        $this->assertGreaterThan(20, $total);
    }

    public function test_el_seeder_de_roles_no_pisa_la_configuracion_del_administrador(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);

        $cobrador = Rol::where('nombre', 'Cobrador')->firstOrFail();
        $cobrador->permisos()->sync([]);

        $this->seed(RolSeeder::class);

        // §5.4: la configuración de permisos la manda el administrador, no el seeder.
        $this->assertSame(0, $cobrador->fresh()->permisos()->count());
    }

    public function test_el_rol_de_acceso_total_hereda_los_permisos_de_modulos_nuevos(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);

        // Un módulo nuevo agrega su permiso al catálogo…
        Permiso::create(['clave' => 'gafetes.emitir', 'descripcion' => 'Emitir gafetes.', 'modulo' => 'gafetes']);
        $this->seed(RolSeeder::class);

        // …y el rol de acceso total lo adquiere, si no nadie podría administrarlo.
        $admin = Rol::where('nombre', 'Administrador')->firstOrFail();
        $this->assertTrue($admin->permisos->contains('clave', 'gafetes.emitir'));
        $this->assertSame(Permiso::count(), $admin->permisos()->count());
    }
}
