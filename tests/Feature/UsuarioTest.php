<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Módulo de cuentas de sistema (§3.1, §5) y alta de cuenta junto con la persona.
 */
class UsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Las rutas exigen sesión y permiso (§5.6).
        $this->actuandoComo(
            'usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'usuarios.desactivar',
            'colaboradores.ver', 'colaboradores.crear', 'colaboradores.editar',
        );
    }

    /**
     * Payload de alta de cuenta con las credenciales y el rol.
     *
     * @return array<string, mixed>
     */
    private function credenciales(array $extra = []): array
    {
        return array_merge([
            'email' => 'juan.perez@arod.test',
            'password' => 'secreto-largo',
            'password_confirmation' => 'secreto-largo',
            'rol_id' => Rol::factory()->create()->id,
        ], $extra);
    }

    // --- Módulo de usuarios ------------------------------------------------

    public function test_lista_cuentas_ordenadas_por_correo(): void
    {
        User::factory()->create(['email' => 'zulema@arod.test']);
        User::factory()->create(['email' => 'ana@arod.test']);

        // +1 por la cuenta de la sesión (ver TestCase::actuandoComo).
        $this->getJson('/api/usuarios')
            ->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data')
            ->assertJsonPath('data.0.email', 'ana@arod.test');
    }

    public function test_el_index_expone_solo_los_campos_del_resource_y_nunca_la_contrasena(): void
    {
        User::factory()->create();

        $response = $this->getJson('/api/usuarios');

        $response->assertOk()->assertJsonStructure([
            'data' => [[
                'id', 'email', 'activo', 'persona_id', 'rol_id',
                'persona' => ['id', 'numero_empleado', 'nombre_completo', 'estado'],
                'rol' => ['id', 'nombre', 'activo'],
                'puede_operar', 'created_at', 'updated_at',
            ]],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        // El hash tampoco debe salir: no es secreto reversible, pero regalarlo es regalar
        // material para un ataque de diccionario offline.
        $response->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_el_index_pagina_y_acota_per_page_a_100(): void
    {
        User::factory()->count(12)->create();

        $this->getJson('/api/usuarios?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 12 + self::REGISTROS_DE_SESION);

        $this->getJson('/api/usuarios?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_busca_por_correo_y_por_datos_de_la_persona(): void
    {
        $persona = Persona::factory()->create([
            'nombre' => 'Guadalupe',
            'primer_apellido' => 'Ramirez',
            'numero_empleado' => '4821',
        ]);
        User::factory()->create(['persona_id' => $persona->id, 'email' => 'lupita@arod.test']);
        User::factory()->create(['email' => 'otro@arod.test']);

        $this->getJson('/api/usuarios?q=lupita')->assertOk()->assertJsonCount(1, 'data');
        // El correo vive en users y el resto en personas: la búsqueda cruza la relación.
        $this->getJson('/api/usuarios?q=Ramirez')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/usuarios?q=4821')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'lupita@arod.test');
    }

    public function test_la_busqueda_no_anula_el_filtro_de_estado(): void
    {
        User::factory()->create(['email' => 'activa@arod.test']);
        User::factory()->inactiva()->create(['email' => 'suspendida@arod.test']);

        $this->getJson('/api/usuarios?q=arod&activo=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'activa@arod.test');
    }

    public function test_filtra_por_rol(): void
    {
        // Sirve para localizar a quién reasignar antes de desactivar un rol (§5.5).
        $rol = Rol::factory()->create();
        User::factory()->create(['rol_id' => $rol->id, 'email' => 'delrol@arod.test']);
        User::factory()->create();

        $this->getJson("/api/usuarios?rol_id={$rol->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'delrol@arod.test');
    }

    public function test_crea_la_cuenta_de_una_persona(): void
    {
        $persona = Persona::factory()->create();
        $rol = Rol::factory()->create(['nombre' => 'Cobrador']);

        $response = $this->postJson('/api/usuarios', $this->credenciales([
            'persona_id' => $persona->id,
            'rol_id' => $rol->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.email', 'juan.perez@arod.test')
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.persona.id', $persona->id)
            ->assertJsonPath('data.rol.nombre', 'Cobrador')
            ->assertJsonPath('data.puede_operar', true);

        // La contraseña se guarda hasheada por el cast del modelo, nunca en claro.
        // Se busca por correo y no con `first()`: la cuenta de la sesión también está en la tabla.
        $cuenta = User::where('email', 'juan.perez@arod.test')->firstOrFail();
        $this->assertNotSame('secreto-largo', $cuenta->password);
        $this->assertTrue(Hash::check('secreto-largo', $cuenta->password));
    }

    public function test_una_persona_no_puede_tener_dos_cuentas(): void
    {
        // §3.1: máximo una cuenta por persona. Debe ser un 422 explicable, no el 500 del unique.
        $persona = Persona::factory()->create();
        User::factory()->create(['persona_id' => $persona->id]);

        $this->postJson('/api/usuarios', $this->credenciales(['persona_id' => $persona->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('persona_id');
    }

    public function test_no_se_crea_una_cuenta_para_una_persona_dada_de_baja(): void
    {
        // §3.3: una persona inactiva no puede iniciar sesión, así que la cuenta no serviría.
        $persona = Persona::factory()->inactiva()->create();

        $this->postJson('/api/usuarios', $this->credenciales(['persona_id' => $persona->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('persona_id');
    }

    public function test_el_correo_es_obligatorio_valido_y_unico(): void
    {
        $persona = Persona::factory()->create();
        User::factory()->create(['email' => 'ocupado@arod.test']);

        $this->postJson('/api/usuarios', $this->credenciales(['persona_id' => $persona->id, 'email' => '']))
            ->assertStatus(422)->assertJsonValidationErrorFor('email');

        $this->postJson('/api/usuarios', $this->credenciales(['persona_id' => $persona->id, 'email' => 'no-es-correo']))
            ->assertStatus(422)->assertJsonValidationErrorFor('email');

        $this->postJson('/api/usuarios', $this->credenciales(['persona_id' => $persona->id, 'email' => 'ocupado@arod.test']))
            ->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_la_contrasena_exige_confirmacion_y_longitud_minima(): void
    {
        $persona = Persona::factory()->create();

        // Sin confirmación: un admin que teclea mal deja al usuario fuera sin enterarse.
        $this->postJson('/api/usuarios', $this->credenciales([
            'persona_id' => $persona->id,
            'password_confirmation' => 'otra-cosa',
        ]))->assertStatus(422)->assertJsonValidationErrorFor('password');

        $this->postJson('/api/usuarios', $this->credenciales([
            'persona_id' => $persona->id,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ]))->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_la_cuenta_debe_tener_un_rol_existente_y_activo(): void
    {
        // §5.1: exactamente un rol. La columna es nullable solo por el orden de migraciones.
        $persona = Persona::factory()->create();

        $this->postJson('/api/usuarios', array_merge(
            $this->credenciales(['persona_id' => $persona->id]),
            ['rol_id' => null],
        ))->assertStatus(422)->assertJsonValidationErrorFor('rol_id');

        $inactivo = Rol::factory()->inactivo()->create();

        $this->postJson('/api/usuarios', $this->credenciales([
            'persona_id' => $persona->id,
            'rol_id' => $inactivo->id,
        ]))->assertStatus(422)->assertJsonValidationErrorFor('rol_id');
    }

    public function test_muestra_una_cuenta_y_404_si_no_existe(): void
    {
        $cuenta = User::factory()->create(['email' => 'ana@arod.test']);

        $this->getJson("/api/usuarios/{$cuenta->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'ana@arod.test');

        $this->getJson('/api/usuarios/999999')->assertNotFound();
    }

    public function test_actualiza_el_correo_y_el_rol(): void
    {
        $cuenta = User::factory()->create();
        $nuevoRol = Rol::factory()->create(['nombre' => 'Supervisor']);

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => 'nuevo@arod.test',
            'rol_id' => $nuevoRol->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.email', 'nuevo@arod.test')
            ->assertJsonPath('data.rol.nombre', 'Supervisor');
    }

    public function test_la_cuenta_no_puede_transferirse_a_otra_persona(): void
    {
        // Mover el acceso a otra identidad conservando el historial de la primera.
        $cuenta = User::factory()->create();
        $otra = Persona::factory()->create();

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $otra->id,
            'email' => $cuenta->email,
            'rol_id' => $cuenta->rol_id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('persona_id');

        $this->assertDatabaseHas('users', ['id' => $cuenta->id, 'persona_id' => $cuenta->persona_id]);
    }

    public function test_actualizar_no_pide_ni_cambia_la_contrasena(): void
    {
        $cuenta = User::factory()->create();
        $hashOriginal = $cuenta->password;

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => 'otro@arod.test',
            'rol_id' => $cuenta->rol_id,
        ])->assertOk();

        $this->assertSame($hashOriginal, $cuenta->fresh()->password);
    }

    public function test_actualizar_conserva_un_rol_desactivado_despues(): void
    {
        // El rol se dio de baja después de asignarlo: corregir el correo no debe bloquearse.
        $rol = Rol::factory()->create();
        $cuenta = User::factory()->create(['rol_id' => $rol->id]);
        $rol->update(['activo' => false]);

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => 'corregido@arod.test',
            'rol_id' => $rol->id,
        ])->assertOk()->assertJsonPath('data.email', 'corregido@arod.test');
    }

    public function test_restablece_la_contrasena(): void
    {
        $cuenta = User::factory()->create();

        $this->patchJson("/api/usuarios/{$cuenta->id}/password", [
            'password' => 'nueva-contrasena',
            'password_confirmation' => 'nueva-contrasena',
        ])->assertOk();

        $this->assertTrue(Hash::check('nueva-contrasena', $cuenta->fresh()->password));
    }

    public function test_restablecer_exige_confirmacion(): void
    {
        $cuenta = User::factory()->create();

        $this->patchJson("/api/usuarios/{$cuenta->id}/password", [
            'password' => 'nueva-contrasena',
            'password_confirmation' => 'otra',
        ])->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_suspende_y_reactiva_la_cuenta_sin_tocar_a_la_persona(): void
    {
        $persona = Persona::factory()->create();
        $cuenta = User::factory()->create(['persona_id' => $persona->id]);

        $this->patchJson("/api/usuarios/{$cuenta->id}/suspender")
            ->assertOk()
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.puede_operar', false);

        // La persona sigue ACTIVA: puede usar su gafete aunque no entre al sistema.
        $this->assertDatabaseHas('personas', ['id' => $persona->id, 'estado' => 'ACTIVO']);

        $this->patchJson("/api/usuarios/{$cuenta->id}/reactivar")
            ->assertOk()
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.puede_operar', true);
    }

    public function test_reactivar_la_cuenta_no_basta_si_la_persona_esta_de_baja(): void
    {
        // §3.3: el estado de la persona manda. `activo` y `puede_operar` son cosas distintas
        // y por eso el recurso expone las dos.
        $persona = Persona::factory()->inactiva()->create();
        $cuenta = User::factory()->inactiva()->create(['persona_id' => $persona->id]);

        $this->patchJson("/api/usuarios/{$cuenta->id}/reactivar")
            ->assertOk()
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.puede_operar', false);
    }

    public function test_no_existe_borrado_fisico_de_cuentas(): void
    {
        $cuenta = User::factory()->create();

        $this->deleteJson("/api/usuarios/{$cuenta->id}")->assertStatus(405);
        $this->assertDatabaseHas('users', ['id' => $cuenta->id]);
    }

    // --- Alta de cuenta junto con la persona (§3.1) -------------------------

    /**
     * @return array<string, mixed>
     */
    private function altaPersona(array $extra = []): array
    {
        return array_merge([
            'numero_empleado' => '7788',
            'nombre' => 'Juan',
            'primer_apellido' => 'Pérez',
            'departamento_id' => Departamento::factory()->create()->id,
        ], $extra);
    }

    public function test_el_alta_de_persona_sin_cuenta_sigue_funcionando(): void
    {
        // §3.1: la cuenta es opcional; omitir el bloque es el caso normal.
        $response = $this->postJson('/api/personas', $this->altaPersona())
            ->assertCreated()
            ->assertJsonPath('data.cuenta', null);

        $this->assertDatabaseMissing('users', ['persona_id' => $response->json('data.id')]);
    }

    public function test_el_alta_de_persona_puede_crear_su_cuenta_de_sistema(): void
    {
        $rol = Rol::factory()->create(['nombre' => 'Cobrador']);

        $response = $this->postJson('/api/personas', $this->altaPersona([
            'cuenta' => [
                'email' => 'juan@arod.test',
                'password' => 'secreto-largo',
                'password_confirmation' => 'secreto-largo',
                'rol_id' => $rol->id,
            ],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.cuenta.email', 'juan@arod.test')
            ->assertJsonPath('data.cuenta.activo', true)
            ->assertJsonPath('data.cuenta.rol.nombre', 'Cobrador');

        $cuenta = User::where('email', 'juan@arod.test')->firstOrFail();
        $this->assertSame($response->json('data.id'), $cuenta->persona_id);
        $this->assertTrue(Hash::check('secreto-largo', $cuenta->password));
    }

    public function test_si_la_cuenta_es_invalida_no_se_crea_la_persona(): void
    {
        // El alta es transaccional: una persona guardada sin su cuenta dejaría al capturista
        // adivinando si debe reintentar el alta completa o solo la cuenta.
        $this->postJson('/api/personas', $this->altaPersona([
            'cuenta' => [
                'email' => 'no-es-correo',
                'password' => 'secreto-largo',
                'password_confirmation' => 'secreto-largo',
                'rol_id' => Rol::factory()->create()->id,
            ],
        ]))->assertStatus(422)->assertJsonValidationErrorFor('cuenta.email');

        // Ni la persona ni la cuenta llegaron a guardarse: el alta es transaccional.
        $this->assertDatabaseMissing('personas', ['numero_empleado' => '7788']);
        $this->assertDatabaseMissing('users', ['email' => 'no-es-correo']);
    }

    public function test_el_alta_con_cuenta_valida_el_rol_igual_que_el_modulo_de_usuarios(): void
    {
        // Las dos entradas comparten CuentaSistemaRules: si una fuera más laxa, sería el
        // hueco por donde se cuelan cuentas mal formadas.
        $inactivo = Rol::factory()->inactivo()->create();

        $this->postJson('/api/personas', $this->altaPersona([
            'cuenta' => [
                'email' => 'juan@arod.test',
                'password' => 'secreto-largo',
                'password_confirmation' => 'secreto-largo',
                'rol_id' => $inactivo->id,
            ],
        ]))->assertStatus(422)->assertJsonValidationErrorFor('cuenta.rol_id');

        $this->assertDatabaseMissing('personas', ['numero_empleado' => '7788']);
    }

    public function test_editar_una_persona_no_toca_su_cuenta(): void
    {
        // Cambiar el correo, el rol o la contraseña va por /api/usuarios: el formulario de
        // la persona no debe convertirse en una segunda puerta a las credenciales.
        $persona = Persona::factory()->create();
        $cuenta = User::factory()->create(['persona_id' => $persona->id, 'email' => 'original@arod.test']);

        $this->putJson("/api/personas/{$persona->id}", [
            'numero_empleado' => $persona->numero_empleado,
            'nombre' => 'Renombrado',
            'primer_apellido' => $persona->primer_apellido,
            'departamento_id' => $persona->departamento_id,
            'cuenta' => ['email' => 'secuestrada@arod.test'],
        ])->assertOk()->assertJsonPath('data.nombre', 'Renombrado');

        $this->assertSame('original@arod.test', $cuenta->fresh()->email);
    }

    public function test_el_filtro_sin_cuenta_lista_a_quienes_pueden_recibir_una(): void
    {
        // Lo usa el formulario de alta de cuentas: ofrecer a alguien que ya tiene una solo
        // produciría un 422 (§3.1).
        $sinCuenta = Persona::factory()->create(['nombre' => 'Disponible']);
        $conCuenta = Persona::factory()->create(['nombre' => 'YaTiene']);
        User::factory()->create(['persona_id' => $conCuenta->id]);

        $this->getJson('/api/personas?sin_cuenta=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sinCuenta->id);

        // Sin el parámetro se siguen viendo ambas (+1 por la persona de la sesión).
        $this->getJson('/api/personas')
            ->assertOk()
            ->assertJsonCount(2 + self::REGISTROS_DE_SESION, 'data');
    }

    public function test_el_filtro_sin_cuenta_se_combina_con_el_de_estado(): void
    {
        Persona::factory()->create(['nombre' => 'ActivaSinCuenta']);
        Persona::factory()->inactiva()->create(['nombre' => 'InactivaSinCuenta']);

        // El formulario pide las dos cosas: activa (§3.3) y sin cuenta (§3.1).
        $this->getJson('/api/personas?sin_cuenta=1&estado=ACTIVO')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'ActivaSinCuenta');
    }

    public function test_la_persona_muestra_si_tiene_cuenta_en_el_listado(): void
    {
        $conCuenta = Persona::factory()->create(['nombre' => 'ConAcceso']);
        User::factory()->create(['persona_id' => $conCuenta->id, 'email' => 'acceso@arod.test']);
        Persona::factory()->create(['nombre' => 'SinAcceso']);

        $response = $this->getJson('/api/personas?q=Acceso')->assertOk();

        $cuentas = collect($response->json('data'))->keyBy('nombre');
        $this->assertSame('acceso@arod.test', $cuentas['ConAcceso']['cuenta']['email']);
        $this->assertNull($cuentas['SinAcceso']['cuenta']);
    }
}
