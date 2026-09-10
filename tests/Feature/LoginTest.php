<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Inicio y cierre de sesión (§3.1), y la puerta que impone §3.3.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El rate limiter persiste entre pruebas dentro del mismo proceso.
        RateLimiter::clear('ana@arod.test|127.0.0.1');

        // Sanctum solo trata como stateful (con sesión y CSRF) las peticiones que vienen del
        // frontend, y lo decide por la cabecera Referer/Origin. El navegador siempre la manda;
        // el cliente de pruebas no, así que aquí se imita para ejercitar la ruta real.
        $this->withHeader('Referer', config('app.url'));
    }

    private function cuenta(array $atributos = [], array $persona = [], array $rol = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'ana@arod.test',
            'password' => 'contrasena-valida',
            'persona_id' => Persona::factory()->create($persona)->id,
            'rol_id' => Rol::factory()->create($rol)->id,
        ], $atributos));
    }

    public function test_inicia_sesion_con_credenciales_validas(): void
    {
        $cuenta = $this->cuenta();

        $response = $this->postJson('/api/login', [
            'email' => 'ana@arod.test',
            'password' => 'contrasena-valida',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $cuenta->id)
            ->assertJsonPath('data.email', 'ana@arod.test');

        $this->assertAuthenticatedAs($cuenta);
    }

    public function test_el_login_nunca_devuelve_la_contrasena(): void
    {
        $this->cuenta();

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_el_login_devuelve_los_permisos_del_rol(): void
    {
        // La UI los usa para saber qué ofrecer; la comprobación real sigue en backend (§5.6).
        $rol = Rol::factory()->create();
        $rol->permisos()->sync(Permiso::factory()->create(['clave' => 'pagos.confirmar']));
        $this->cuenta(['rol_id' => $rol->id]);

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertOk()
            ->assertJsonPath('data.permisos', ['pagos.confirmar']);
    }

    public function test_rechaza_una_contrasena_incorrecta(): void
    {
        $this->cuenta();

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'equivocada'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');

        $this->assertGuest();
    }

    public function test_el_correo_y_la_contrasena_son_obligatorios(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_no_revela_si_la_cuenta_existe(): void
    {
        // Un correo inexistente y una contraseña equivocada dan exactamente el mismo mensaje:
        // distinguirlos regalaría quién trabaja aquí.
        $this->cuenta();

        $inexistente = $this->postJson('/api/login', ['email' => 'nadie@arod.test', 'password' => 'x']);
        $equivocada = $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'x']);

        $this->assertSame(
            $inexistente->json('errors.email'),
            $equivocada->json('errors.email'),
        );
    }

    public function test_una_persona_dada_de_baja_no_puede_iniciar_sesion(): void
    {
        // §3.3, la regla central: la baja de la persona corta el acceso aunque la cuenta y el
        // rol sigan activos y la contraseña sea correcta.
        $this->cuenta(persona: ['estado' => 'INACTIVO']);

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'La persona está dada de baja, así que no puede iniciar sesión.');

        $this->assertGuest();
    }

    public function test_una_cuenta_suspendida_no_puede_iniciar_sesion(): void
    {
        $this->cuenta(['activo' => false]);

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Esta cuenta está suspendida.');

        $this->assertGuest();
    }

    public function test_un_rol_desactivado_impide_iniciar_sesion(): void
    {
        // Sin rol activo no hay permisos efectivos: entrar solo produciría 403 en todas partes.
        $this->cuenta(rol: ['activo' => false]);

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'El rol de esta cuenta está desactivado, así que no tiene permisos efectivos.');

        $this->assertGuest();
    }

    public function test_el_motivo_solo_se_revela_tras_acertar_la_contrasena(): void
    {
        // Con la contraseña equivocada, una cuenta suspendida responde lo mismo que cualquier
        // otra: el motivo no es una vía para averiguar el estado de nadie.
        $this->cuenta(['activo' => false]);

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'equivocada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales no coinciden con nuestros registros.');
    }

    public function test_bloquea_tras_varios_intentos_fallidos(): void
    {
        $this->cuenta();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'equivocada'])
                ->assertStatus(422);
        }

        // El sexto intento ya no llega a comprobar la contraseña, ni siquiera la correcta.
        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($mensaje) => str_contains($mensaje, 'Demasiados intentos'));

        $this->assertGuest();
    }

    public function test_un_inicio_correcto_limpia_el_contador_de_intentos(): void
    {
        $this->cuenta();

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'equivocada'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])->assertOk();

        $this->assertSame(0, RateLimiter::attempts('ana@arod.test|127.0.0.1'));
    }

    public function test_me_devuelve_la_cuenta_autenticada(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($cuenta)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $cuenta->id)
            ->assertJsonStructure(['data' => ['id', 'email', 'persona', 'rol', 'permisos']]);
    }

    public function test_me_responde_401_sin_sesion(): void
    {
        // Es lo que el router del SPA usa para mandar al login sin tener que adivinar.
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_cierra_la_sesion(): void
    {
        // Se inicia sesión de verdad en vez de usar `actingAs`, para pasar por el ciclo
        // completo de la sesión.
        //
        // Se comprueba el guard `web` explícitamente por dos motivos:
        //
        // 1. El cliente de pruebas comparte el almacén de sesión entre peticiones en vez de
        //    llevar la cookie como un navegador, así que un 401 posterior en /api/me no
        //    probaría lo que parece. Esa parte se verifica en el navegador.
        // 2. `auth:sanctum` deja `sanctum` como guard por defecto, y su RequestGuard cachea el
        //    usuario que resolvió AL ENTRAR a la petición de logout. Un `assertGuest()` sin
        //    argumento leería esa caché —anterior al logout— y no la sesión.
        $this->cuenta();

        $this->postJson('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertOk();

        $this->postJson('/api/logout')->assertOk();

        $this->assertGuest('web');
    }

    public function test_logout_requiere_sesion(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_la_sesion_se_regenera_al_iniciar(): void
    {
        // Contra fijación de sesión: el id que traía el visitante se descarta al autenticarse.
        $this->cuenta();

        $this->get('/');
        $anterior = session()->getId();

        $this->post('/api/login', ['email' => 'ana@arod.test', 'password' => 'contrasena-valida'])
            ->assertOk();

        $this->assertNotSame($anterior, session()->getId());
    }
}
