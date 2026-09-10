<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reglas de la cuenta de sistema opcional (§3.1) y de la comprobación de permisos (§5.6).
 */
class CuentaSistemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_persona_puede_no_tener_cuenta(): void
    {
        // §3.1: la cuenta es opcional. Sin cuenta la persona sigue siendo consumidora del
        // comedor mediante su gafete; solo no entra a los módulos administrativos.
        $persona = Persona::factory()->create();

        $this->assertNull($persona->cuenta);
    }

    public function test_una_persona_tiene_como_maximo_una_cuenta(): void
    {
        $persona = Persona::factory()->create();
        User::factory()->create(['persona_id' => $persona->id]);

        // El unique de users.persona_id es lo que impone la regla a nivel de BD.
        $this->expectException(QueryException::class);
        User::factory()->create(['persona_id' => $persona->id]);
    }

    public function test_la_cuenta_conoce_a_su_persona_y_su_rol(): void
    {
        $persona = Persona::factory()->create(['nombre' => 'Juan']);
        $rol = Rol::factory()->create(['nombre' => 'Cobrador']);
        $cuenta = User::factory()->create(['persona_id' => $persona->id, 'rol_id' => $rol->id]);

        $this->assertSame('Juan', $cuenta->persona->nombre);
        $this->assertSame('Cobrador', $cuenta->rol->nombre);
        $this->assertSame($cuenta->id, $persona->fresh()->cuenta->id);
    }

    public function test_la_contrasena_nunca_viaja_en_texto_plano_ni_se_serializa(): void
    {
        $cuenta = User::factory()->create();

        $this->assertNotSame('password', $cuenta->password);
        $this->assertTrue(Hash::check('password', $cuenta->password));
        $this->assertArrayNotHasKey('password', $cuenta->toArray());
        $this->assertArrayNotHasKey('remember_token', $cuenta->toArray());
    }

    public function test_tiene_permiso_lee_los_permisos_del_rol(): void
    {
        $rol = Rol::factory()->create();
        $rol->permisos()->sync(Permiso::factory()->create(['clave' => 'pagos.confirmar']));
        $cuenta = User::factory()->create(['rol_id' => $rol->id]);

        $this->assertTrue($cuenta->tienePermiso('pagos.confirmar'));
        $this->assertFalse($cuenta->tienePermiso('pagos.cancelar'));
    }

    public function test_una_persona_inactiva_no_puede_operar(): void
    {
        // §3.3: una persona dada de baja pierde todas sus capacidades, incluido el acceso.
        $rol = Rol::factory()->create();
        $rol->permisos()->sync(Permiso::factory()->create(['clave' => 'pagos.confirmar']));
        $persona = Persona::factory()->inactiva()->create();
        $cuenta = User::factory()->create(['persona_id' => $persona->id, 'rol_id' => $rol->id]);

        $this->assertFalse($cuenta->puedeOperar());
        $this->assertFalse($cuenta->tienePermiso('pagos.confirmar'));
    }

    public function test_el_estado_de_la_persona_se_lee_del_origen_no_de_una_copia(): void
    {
        // Nada replica `personas.estado` en `users`, así que no pueden desincronizarse:
        // dar de baja a la persona corta el acceso sin tocar la cuenta.
        $persona = Persona::factory()->create();
        $cuenta = User::factory()->create(['persona_id' => $persona->id]);

        $this->assertTrue($cuenta->puedeOperar());

        $persona->update(['estado' => 'INACTIVO']);

        $this->assertFalse($cuenta->fresh()->puedeOperar());
        $this->assertDatabaseHas('users', ['id' => $cuenta->id, 'activo' => true]);
    }

    public function test_una_cuenta_suspendida_no_puede_operar_aunque_la_persona_este_activa(): void
    {
        // Interruptor independiente: suspender el acceso sin dar de baja a la persona.
        $persona = Persona::factory()->create();
        $cuenta = User::factory()->inactiva()->create(['persona_id' => $persona->id]);

        $this->assertSame('ACTIVO', $persona->estado);
        $this->assertFalse($cuenta->puedeOperar());
    }

    public function test_un_rol_desactivado_deja_sin_permisos_a_sus_usuarios(): void
    {
        $rol = Rol::factory()->inactivo()->create();
        $rol->permisos()->sync(Permiso::factory()->create(['clave' => 'pagos.confirmar']));
        $cuenta = User::factory()->create(['rol_id' => $rol->id]);

        $this->assertFalse($cuenta->puedeOperar());
        $this->assertFalse($cuenta->tienePermiso('pagos.confirmar'));
    }

    public function test_la_cuenta_sobrevive_a_la_baja_y_al_reingreso_de_la_persona(): void
    {
        // §3.3: los datos se conservan. Reactivar a la persona devuelve el acceso.
        $persona = Persona::factory()->create();
        $cuenta = User::factory()->create(['persona_id' => $persona->id]);

        $persona->update(['estado' => 'INACTIVO']);
        $this->assertFalse($cuenta->fresh()->puedeOperar());

        $persona->update(['estado' => 'ACTIVO']);
        $this->assertTrue($cuenta->fresh()->puedeOperar());
    }
}
