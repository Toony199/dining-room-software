<?php

namespace Tests\Feature;

use App\Models\Departamento;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\AdministradorSeeder;
use Database\Seeders\PermisoSeeder;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Protección de los endpoints (§5.6): sesión obligatoria y permiso concreto por operación.
 *
 * La spec es explícita en que ocultar un botón no basta, así que lo que se comprueba aquí es
 * el backend: sin sesión, 401; con sesión pero sin el permiso, 403.
 */
class PermisosRutasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Una ruta por cada verbo y módulo, con el permiso que debería exigir.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function rutasProtegidas(): array
    {
        return [
            'listar personas' => ['getJson', '/api/personas', 'colaboradores.ver'],
            'crear persona' => ['postJson', '/api/personas', 'colaboradores.crear'],
            'listar departamentos' => ['getJson', '/api/departamentos', 'departamentos.ver'],
            'crear departamento' => ['postJson', '/api/departamentos', 'departamentos.crear'],
            'listar roles' => ['getJson', '/api/roles', 'roles.ver'],
            'crear rol' => ['postJson', '/api/roles', 'roles.crear'],
            'catálogo de permisos' => ['getJson', '/api/permisos', 'roles.ver'],
            'listar usuarios' => ['getJson', '/api/usuarios', 'usuarios.ver'],
            'crear usuario' => ['postJson', '/api/usuarios', 'usuarios.crear'],
        ];
    }

    #[DataProvider('rutasProtegidas')]
    public function test_sin_sesion_responde_401(string $metodo, string $ruta): void
    {
        $this->{$metodo}($ruta)->assertUnauthorized();
    }

    #[DataProvider('rutasProtegidas')]
    public function test_con_sesion_pero_sin_el_permiso_responde_403(string $metodo, string $ruta, string $permiso): void
    {
        // La cuenta tiene un permiso real, pero no el que esta ruta exige: así se comprueba
        // que la ruta pide la clave correcta y no simplemente "estar autenticado".
        $this->actuandoComo('permiso.irrelevante');

        $this->{$metodo}($ruta)->assertForbidden();

        $this->assertNotSame('permiso.irrelevante', $permiso);
    }

    #[DataProvider('rutasProtegidas')]
    public function test_con_el_permiso_la_ruta_deja_pasar(string $metodo, string $ruta, string $permiso): void
    {
        $this->actuandoComo($permiso);

        $respuesta = $this->{$metodo}($ruta);

        // Un POST sin cuerpo devuelve 422: llegó al validador, que es lo que se quería probar.
        $this->assertContains($respuesta->status(), [200, 201, 422], "{$metodo} {$ruta} devolvió {$respuesta->status()}");
    }

    public function test_el_403_llega_en_espanol(): void
    {
        // El mensaje por defecto de Laravel es "This action is unauthorized." y llegaba tal
        // cual a la interfaz, aunque se creía traducido.
        $this->actuandoComo('permiso.irrelevante');

        $this->getJson('/api/personas')
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_ver_no_alcanza_para_escribir(): void
    {
        // El error clásico de proteger un módulo entero con una sola clave.
        $this->actuandoComo('colaboradores.ver');

        // Una persona que sí existe: con un id inventado el 404 del route binding taparía el
        // 403 y la prueba no demostraría nada.
        $persona = Persona::factory()->create();

        $this->getJson('/api/personas')->assertOk();
        $this->postJson('/api/personas', [])->assertForbidden();
        $this->putJson("/api/personas/{$persona->id}", [])->assertForbidden();
        $this->patchJson("/api/personas/{$persona->id}/desactivar")->assertForbidden();
    }

    public function test_dar_de_alta_personal_no_permite_crear_cuentas(): void
    {
        // Escalada de privilegios: el bloque `cuenta` del alta de persona crea un usuario. Sin
        // esta comprobación, quien solo puede registrar personal podría fabricarse una cuenta
        // con el rol de acceso total.
        $this->actuandoComo('colaboradores.crear');

        $rol = Rol::factory()->create();

        $this->postJson('/api/personas', [
            'numero_empleado' => '9090',
            'nombre' => 'Intruso',
            'primer_apellido' => 'Escalada',
            'departamento_id' => Departamento::factory()->create()->id,
            'cuenta' => [
                'email' => 'intruso@arod.test',
                'password' => 'contrasena-larga',
                'password_confirmation' => 'contrasena-larga',
                'rol_id' => $rol->id,
            ],
        ])->assertForbidden();

        $this->assertDatabaseMissing('personas', ['numero_empleado' => '9090']);
        $this->assertDatabaseMissing('users', ['email' => 'intruso@arod.test']);
    }

    public function test_con_ambos_permisos_si_puede_crear_persona_y_cuenta(): void
    {
        $this->actuandoComo('colaboradores.crear', 'usuarios.crear');

        $rol = Rol::factory()->create();

        $this->postJson('/api/personas', [
            'numero_empleado' => '9091',
            'nombre' => 'Legitima',
            'primer_apellido' => 'Alta',
            'departamento_id' => Departamento::factory()->create()->id,
            'cuenta' => [
                'email' => 'legitima@arod.test',
                'password' => 'contrasena-larga',
                'password_confirmation' => 'contrasena-larga',
                'rol_id' => $rol->id,
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'legitima@arod.test']);
    }

    public function test_una_persona_dada_de_baja_pierde_el_acceso_en_la_siguiente_peticion(): void
    {
        // §3.3: no hace falta esperar a que caduque la sesión. El Gate consulta el estado en
        // cada petición porque `tienePermiso` lo comprueba en el momento.
        $cuenta = $this->actuandoComo('colaboradores.ver');

        $this->getJson('/api/personas')->assertOk();

        $cuenta->persona->update(['estado' => 'INACTIVO']);

        $this->getJson('/api/personas')->assertForbidden();
    }

    public function test_desactivar_el_rol_deja_sin_permisos_a_sus_cuentas(): void
    {
        $cuenta = $this->actuandoComo('colaboradores.ver');

        $this->getJson('/api/personas')->assertOk();

        $cuenta->rol->update(['activo' => false]);

        $this->getJson('/api/personas')->assertForbidden();
    }

    public function test_quitarle_un_permiso_al_rol_lo_quita_de_inmediato(): void
    {
        // §5.4: los permisos se consultan contra el rol, nunca se copian a la cuenta.
        $cuenta = $this->actuandoComo('colaboradores.ver');

        $this->getJson('/api/personas')->assertOk();

        $cuenta->rol->permisos()->sync([]);

        // Se reautentica con una instancia limpia porque `actingAs` conserva la misma en
        // memoria, con su relación `rol.permisos` ya cargada. Una petición real siempre
        // rehidrata la cuenta desde la sesión, así que ahí no hay caché que valga.
        $this->actingAs($cuenta->fresh());

        $this->getJson('/api/personas')->assertForbidden();
    }

    // --- Rol protegido y cuenta de arranque (§5.2) --------------------------

    public function test_el_rol_de_acceso_total_no_puede_editarse(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->actuandoComo('roles.ver', 'roles.editar', 'roles.desactivar');

        $admin = Rol::where('nombre', 'Administrador')->firstOrFail();
        $this->assertTrue($admin->esProtegido());

        // Ni siquiera teniendo el permiso: quitarle permisos dejaría la instalación sin nadie
        // capaz de administrarla y sin forma de arreglarlo desde la propia aplicación.
        $this->putJson("/api/roles/{$admin->id}", ['nombre' => 'Otro', 'permisos' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.rol.0', 'Este rol está protegido.');

        $this->assertDatabaseHas('roles', ['id' => $admin->id, 'nombre' => 'Administrador']);
        $this->assertSame(Permiso::count(), $admin->fresh()->permisos()->count());
    }

    public function test_el_rol_de_acceso_total_no_puede_desactivarse(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->actuandoComo('roles.desactivar');

        $admin = Rol::where('nombre', 'Administrador')->firstOrFail();

        $this->patchJson("/api/roles/{$admin->id}/desactivar")
            ->assertStatus(422)
            ->assertJsonPath('errors.rol.0', 'Este rol está protegido.');

        $this->assertDatabaseHas('roles', ['id' => $admin->id, 'activo' => true]);
    }

    public function test_los_demas_roles_siguen_siendo_editables(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->actuandoComo('roles.editar');

        $cobrador = Rol::where('nombre', 'Cobrador')->firstOrFail();
        $this->assertFalse($cobrador->esProtegido());

        $this->putJson("/api/roles/{$cobrador->id}", ['nombre' => 'Cajero'])->assertOk();
    }

    public function test_el_resource_publica_la_bandera_de_protegido(): void
    {
        // La UI la usa para deshabilitar el botón; el backend rechaza igualmente.
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->actuandoComo('roles.ver');

        $respuesta = $this->getJson('/api/roles?q=Administrador')->assertOk();

        $this->assertTrue($respuesta->json('data.0.protegido'));
    }

    public function test_el_seeder_crea_la_cuenta_administradora_de_arranque(): void
    {
        // Sin ella, un despliegue nuevo no tendría con qué entrar a crear las demás cuentas.
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->seed(AdministradorSeeder::class);

        $cuenta = User::with('rol')->where('email', config('comedor.admin.email'))->firstOrFail();

        $this->assertSame('Administrador', $cuenta->rol->nombre);
        $this->assertTrue($cuenta->puedeOperar());
        $this->assertSame(Permiso::count(), $cuenta->rol->permisos()->count());
        $this->assertSame('ACTIVO', $cuenta->persona->estado);
    }

    public function test_el_seeder_marca_la_cuenta_de_arranque_como_protegida(): void
    {
        $cuenta = $this->cuentaDeArranque();

        $this->assertTrue($cuenta->esProtegida());
        $this->assertSame('dev@arod.com.mx', $cuenta->email);
    }

    public function test_el_seeder_recupera_una_cuenta_inutilizable_sin_tocar_sus_credenciales(): void
    {
        // Correr el seeder es la vía de recuperación si la cuenta quedó inservible por un
        // cambio directo en la base. Pero correo y contraseña los decide el administrador.
        $cuenta = $this->cuentaDeArranque();
        $cuenta->update(['email' => 'otro.correo@arod.test']);
        $hash = $cuenta->fresh()->password;

        $cuenta->persona->update(['estado' => 'INACTIVO']);
        $cuenta->update(['activo' => false, 'rol_id' => Rol::factory()->create()->id]);
        $this->assertFalse($cuenta->fresh()->puedeOperar());

        $this->seed(AdministradorSeeder::class);

        $cuenta = $cuenta->fresh(['persona', 'rol']);
        $this->assertTrue($cuenta->puedeOperar());
        $this->assertSame('Administrador', $cuenta->rol->nombre);
        $this->assertSame('otro.correo@arod.test', $cuenta->email);
        $this->assertSame($hash, $cuenta->password);
    }

    public function test_el_seeder_no_duplica_la_cuenta_si_cambio_el_correo(): void
    {
        // Si buscara solo por el correo de la configuración, un administrador que cambió el suyo
        // desde la aplicación recibiría una segunda cuenta en el siguiente despliegue.
        $cuenta = $this->cuentaDeArranque();
        $cuenta->update(['email' => 'cambiado@arod.test']);

        $this->seed(AdministradorSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(1, User::where('protegido', true)->count());
    }

    // --- Cuenta protegida: no se puede dejar la instalación sin administrador -

    public function test_no_se_puede_dar_de_baja_a_la_persona_de_la_cuenta_protegida(): void
    {
        // El caso que dejó fuera al administrador: darse de baja a sí mismo desde Personas.
        $cuenta = $this->cuentaDeArranque();
        $this->actingAs($cuenta);

        $this->patchJson("/api/personas/{$cuenta->persona_id}/desactivar")
            ->assertStatus(422)
            ->assertJsonPath('errors.persona.0', 'La persona tiene la cuenta administradora del sistema.');

        $this->assertDatabaseHas('personas', ['id' => $cuenta->persona_id, 'estado' => 'ACTIVO']);
        $this->getJson('/api/personas')->assertOk();
    }

    public function test_las_demas_personas_con_cuenta_si_pueden_darse_de_baja(): void
    {
        $this->actuandoComo('colaboradores.desactivar');
        $otra = User::factory()->create();

        $this->patchJson("/api/personas/{$otra->persona_id}/desactivar")->assertOk();
    }

    public function test_la_cuenta_protegida_no_puede_suspenderse(): void
    {
        $cuenta = $this->cuentaDeArranque();
        $this->actingAs($cuenta);

        $this->patchJson("/api/usuarios/{$cuenta->id}/suspender")
            ->assertStatus(422)
            ->assertJsonPath('errors.usuario.0', 'Esta cuenta está protegida.');

        $this->assertDatabaseHas('users', ['id' => $cuenta->id, 'activo' => true]);
    }

    public function test_la_cuenta_protegida_no_puede_cambiar_de_rol(): void
    {
        // Pasarla a un rol sin permisos es otra forma de quedarse sin administrador.
        $cuenta = $this->cuentaDeArranque();
        $this->actingAs($cuenta);
        $consulta = Rol::where('nombre', 'Consulta')->firstOrFail();

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => $cuenta->email,
            'rol_id' => $consulta->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('rol_id');

        $this->assertSame('Administrador', $cuenta->fresh()->rol->nombre);
    }

    public function test_la_cuenta_protegida_si_puede_cambiar_su_correo_y_contrasena(): void
    {
        // Proteger no es congelar: el administrador necesita cambiar la contraseña generada.
        $cuenta = $this->cuentaDeArranque();
        $this->actingAs($cuenta);

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => 'nuevo.admin@arod.test',
            'rol_id' => $cuenta->rol_id,
        ])->assertOk()->assertJsonPath('data.email', 'nuevo.admin@arod.test');

        $this->patchJson("/api/usuarios/{$cuenta->id}/password", [
            'password' => 'otra-contrasena-larga',
            'password_confirmation' => 'otra-contrasena-larga',
        ])->assertOk();

        $this->assertTrue(Hash::check('otra-contrasena-larga', $cuenta->fresh()->password));
    }

    public function test_los_recursos_publican_que_la_cuenta_esta_protegida(): void
    {
        // La UI lo usa para bloquear el switch; el backend rechaza igualmente.
        $cuenta = $this->cuentaDeArranque();
        $this->actingAs($cuenta);

        $this->getJson("/api/usuarios/{$cuenta->id}")->assertJsonPath('data.protegido', true);
        $this->getJson("/api/personas/{$cuenta->persona_id}")->assertJsonPath('data.cuenta.protegido', true);
    }

    public function test_la_bandera_no_se_puede_asignar_desde_la_api(): void
    {
        // Si se pudiera, cualquiera con `usuarios.crear` fabricaría cuentas imposibles de
        // suspender.
        $this->actuandoComo('usuarios.crear');

        $this->postJson('/api/usuarios', [
            'persona_id' => Persona::factory()->create()->id,
            'email' => 'blindada@arod.test',
            'password' => 'contrasena-larga',
            'password_confirmation' => 'contrasena-larga',
            'rol_id' => Rol::factory()->create()->id,
            'protegido' => true,
        ])->assertCreated()->assertJsonPath('data.protegido', false);
    }

    public function test_la_cuenta_administradora_puede_con_todos_los_modulos(): void
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->seed(AdministradorSeeder::class);

        $this->actingAs(User::where('email', config('comedor.admin.email'))->firstOrFail());

        // Es el punto de la cuenta de arranque: poder crear el resto de perfiles.
        $this->getJson('/api/usuarios')->assertOk();
        $this->getJson('/api/roles')->assertOk();
        $this->getJson('/api/permisos')->assertOk();
        $this->getJson('/api/personas')->assertOk();
        $this->getJson('/api/departamentos')->assertOk();
        $this->postJson('/api/departamentos', ['nombre' => 'Nuevo'])->assertCreated();
    }

    /**
     * Siembra el catálogo, los roles y la cuenta administradora de arranque.
     */
    private function cuentaDeArranque(): User
    {
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->seed(AdministradorSeeder::class);

        return User::with(['persona', 'rol'])->where('protegido', true)->firstOrFail();
    }
}
