<?php

namespace Tests\Feature;

use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\AdministradorSeeder;
use Database\Seeders\PermisoSeeder;
use Database\Seeders\RolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nadie puede dejarse fuera a sí mismo: ni darse de baja, ni suspender su cuenta, ni cambiarse
 * el rol. Las tres cortan el acceso en la siguiente petición (§3.3, §5.6) y dejarían a esa
 * persona dependiendo de que otro administrador la rescate.
 */
class AutoDesactivacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nadie_puede_darse_de_baja_a_si_mismo(): void
    {
        $cuenta = $this->actuandoComo('colaboradores.desactivar');

        $this->patchJson("/api/personas/{$cuenta->persona_id}/desactivar")
            ->assertStatus(422)
            ->assertJsonPath('errors.persona.0', 'No puedes darte de baja a ti mismo.');

        $this->assertDatabaseHas('personas', ['id' => $cuenta->persona_id, 'estado' => 'ACTIVO']);
        $this->getJson('/api/me')->assertOk();
    }

    public function test_si_puede_dar_de_baja_a_otras_personas(): void
    {
        $this->actuandoComo('colaboradores.desactivar');
        $otra = User::factory()->create();

        $this->patchJson("/api/personas/{$otra->persona_id}/desactivar")->assertOk();
    }

    public function test_nadie_puede_suspender_su_propia_cuenta(): void
    {
        $cuenta = $this->actuandoComo('usuarios.desactivar');

        $this->patchJson("/api/usuarios/{$cuenta->id}/suspender")
            ->assertStatus(422)
            ->assertJsonPath('errors.usuario.0', 'No puedes suspender tu propia cuenta.');

        $this->assertDatabaseHas('users', ['id' => $cuenta->id, 'activo' => true]);
    }

    public function test_si_puede_suspender_otras_cuentas(): void
    {
        $this->actuandoComo('usuarios.desactivar');
        $otra = User::factory()->create();

        $this->patchJson("/api/usuarios/{$otra->id}/suspender")->assertOk();
    }

    public function test_nadie_puede_cambiar_su_propio_rol(): void
    {
        // Un administrador que se pasa a un rol de solo lectura se queda sin forma de volver.
        $cuenta = $this->actuandoComo('usuarios.editar');
        $consulta = Rol::factory()->create(['nombre' => 'Consulta']);

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => $cuenta->email,
            'rol_id' => $consulta->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.rol_id.0', 'No puedes cambiar tu propio rol: podrías quedarte sin acceso. Pídele a otro administrador que lo haga.');

        $this->assertSame($cuenta->rol_id, $cuenta->fresh()->rol_id);
    }

    public function test_si_puede_cambiar_su_propio_correo(): void
    {
        // La regla es no dejarse fuera, no congelar la cuenta propia.
        $cuenta = $this->actuandoComo('usuarios.editar');

        $this->putJson("/api/usuarios/{$cuenta->id}", [
            'persona_id' => $cuenta->persona_id,
            'email' => 'mi.nuevo.correo@arod.test',
            'rol_id' => $cuenta->rol_id,
        ])->assertOk()->assertJsonPath('data.email', 'mi.nuevo.correo@arod.test');
    }

    public function test_si_puede_cambiar_el_rol_de_otras_cuentas(): void
    {
        $this->actuandoComo('usuarios.editar');
        $otra = User::factory()->create();
        $nuevo = Rol::factory()->create();

        $this->putJson("/api/usuarios/{$otra->id}", [
            'persona_id' => $otra->persona_id,
            'email' => $otra->email,
            'rol_id' => $nuevo->id,
        ])->assertOk();
    }

    // --- La protección de la cuenta de arranque no depende de esta regla --------

    public function test_otra_cuenta_tampoco_puede_bloquear_la_cuenta_protegida(): void
    {
        // Con un actor distinto la regla de "a ti mismo" no aplica: lo único que frena estas
        // peticiones es la protección de la cuenta de arranque.
        $this->seed(PermisoSeeder::class);
        $this->seed(RolSeeder::class);
        $this->seed(AdministradorSeeder::class);
        $protegida = User::where('protegido', true)->firstOrFail();

        $this->actuandoComo('colaboradores.desactivar', 'usuarios.desactivar', 'usuarios.editar');

        $this->patchJson("/api/personas/{$protegida->persona_id}/desactivar")
            ->assertStatus(422)
            ->assertJsonPath('errors.persona.0', 'La persona tiene la cuenta administradora del sistema.');

        $this->patchJson("/api/usuarios/{$protegida->id}/suspender")
            ->assertStatus(422)
            ->assertJsonPath('errors.usuario.0', 'Esta cuenta está protegida.');

        $this->putJson("/api/usuarios/{$protegida->id}", [
            'persona_id' => $protegida->persona_id,
            'email' => $protegida->email,
            'rol_id' => Rol::where('nombre', 'Consulta')->value('id'),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.rol_id.0', 'El rol de la cuenta administradora del sistema no puede cambiarse.');

        $this->assertTrue($protegida->fresh()->puedeOperar());
    }

    public function test_el_resource_de_persona_permite_reconocer_la_cuenta_propia(): void
    {
        // La UI compara `cuenta.id` con la sesión para bloquear el switch de la propia fila.
        $cuenta = $this->actuandoComo('colaboradores.ver');
        Persona::factory()->create();

        $this->getJson("/api/personas/{$cuenta->persona_id}")
            ->assertOk()
            ->assertJsonPath('data.cuenta.id', $cuenta->id);
    }
}
