<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Tarifa;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo de precios por día (§6.4, §18).
 *
 * El precio es general para todos y no se edita: se registra otro y el anterior queda cerrado el
 * día previo. Lo que ya se cobró vive congelado en los días de cada periodo (§6.5), así que este
 * catálogo solo dice qué precio se copiará a los periodos que se creen de aquí en adelante.
 */
class TarifaTest extends TestCase
{
    use RefreshDatabase;

    private function registrar(float $precio, string $desde): Tarifa
    {
        return Tarifa::registrar($precio, $desde);
    }

    // --- Alta ---------------------------------------------------------------------

    public function test_registra_el_primer_precio(): void
    {
        $this->actuandoComo('tarifas.editar');

        $this->postJson('/api/tarifas', ['precio' => 50, 'vigente_desde' => today()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.precio', '50.00')
            ->assertJsonPath('data.vigente_hasta', null)
            ->assertJsonPath('data.vigente', true)
            ->assertJsonPath('data.programada', false);
    }

    public function test_registrar_un_precio_cierra_el_anterior_el_dia_previo(): void
    {
        // La línea de tiempo no puede tener huecos ni dos precios el mismo día.
        $this->actuandoComo('tarifas.editar');
        $anterior = $this->registrar(50, today()->subMonth()->toDateString());

        $this->postJson('/api/tarifas', [
            'precio' => 55,
            'vigente_desde' => today()->addDays(3)->toDateString(),
        ])->assertCreated();

        $this->assertSame(today()->addDays(2)->toDateString(), $anterior->fresh()->vigente_hasta->toDateString());
    }

    public function test_un_precio_puede_quedar_programado_para_mas_adelante(): void
    {
        $this->actuandoComo('tarifas.editar');
        $vigente = $this->registrar(50, today()->toDateString());

        $this->postJson('/api/tarifas', [
            'precio' => 60,
            'vigente_desde' => today()->addWeek()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.programada', true)
            ->assertJsonPath('data.vigente', false);

        // Hasta que llegue su fecha, el precio de hoy sigue siendo el anterior.
        $this->assertTrue(Tarifa::vigente()->is($vigente->fresh()));
    }

    public function test_no_permite_un_precio_que_empiece_antes_que_el_ultimo(): void
    {
        $this->actuandoComo('tarifas.editar');
        $this->registrar(50, today()->addWeek()->toDateString());

        $this->postJson('/api/tarifas', [
            'precio' => 60,
            'vigente_desde' => today()->addDays(2)->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.vigente_desde.0', fn ($mensaje) => str_contains($mensaje, 'debe empezar después'));

        $this->assertSame(1, Tarifa::count());
    }

    public function test_no_permite_cambiar_el_pasado(): void
    {
        // Lo ya cobrado quedó congelado en los días del periodo: mover el catálogo hacia atrás no
        // lo cambiaría y solo confundiría el historial (§6.5).
        $this->actuandoComo('tarifas.editar');

        $this->postJson('/api/tarifas', [
            'precio' => 50,
            'vigente_desde' => today()->subDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.vigente_desde.0', 'El precio puede empezar hoy o más adelante, no en una fecha pasada.');
    }

    public function test_exige_un_precio_valido(): void
    {
        $this->actuandoComo('tarifas.editar');
        $hoy = today()->toDateString();

        $this->postJson('/api/tarifas', ['vigente_desde' => $hoy])
            ->assertStatus(422)
            ->assertJsonPath('errors.precio.0', 'Escribe el precio del día.');

        $this->postJson('/api/tarifas', ['precio' => 0, 'vigente_desde' => $hoy])
            ->assertStatus(422)
            ->assertJsonPath('errors.precio.0', 'El precio debe ser mayor que cero.');

        $this->postJson('/api/tarifas', ['precio' => 100000, 'vigente_desde' => $hoy])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('precio');
    }

    public function test_guarda_quien_lo_registro(): void
    {
        // §19: las operaciones administrativas dejan rastro de quién las hizo.
        $cuenta = $this->actuandoComo('tarifas.editar', 'tarifas.ver');

        $this->postJson('/api/tarifas', ['precio' => 50, 'vigente_desde' => today()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.creado_por', fn ($nombre) => str_contains((string) $nombre, self::APELLIDO_DE_SESION));

        $this->assertSame($cuenta->id, Tarifa::first()->creado_por);
    }

    // --- Consulta -------------------------------------------------------------------

    public function test_el_historial_va_del_mas_reciente_al_mas_antiguo(): void
    {
        $this->actuandoComo('tarifas.ver');
        $this->registrar(45, today()->subMonths(2)->toDateString());
        $this->registrar(50, today()->subMonth()->toDateString());
        $this->registrar(55, today()->toDateString());

        $this->getJson('/api/tarifas')
            ->assertOk()
            ->assertJsonPath('data.0.precio', '55.00')
            ->assertJsonPath('data.1.precio', '50.00')
            ->assertJsonPath('data.2.precio', '45.00');
    }

    public function test_entrega_el_precio_que_rige_hoy(): void
    {
        $this->actuandoComo('tarifas.ver');
        $this->registrar(50, today()->subMonth()->toDateString());
        $this->registrar(55, today()->toDateString());
        $this->registrar(60, today()->addWeek()->toDateString());

        $this->getJson('/api/tarifas/vigente')
            ->assertOk()
            ->assertJsonPath('data.precio', '55.00')
            ->assertJsonPath('data.vigente', true);
    }

    public function test_sin_tarifas_registradas_el_vigente_viene_vacio(): void
    {
        // Es lo que mirará la pantalla de periodos para avisar que falta registrar el precio.
        $this->actuandoComo('tarifas.ver');

        $this->getJson('/api/tarifas/vigente')->assertOk()->assertJsonPath('data', null);
    }

    public function test_el_precio_de_una_fecha_es_el_que_regia_ese_dia(): void
    {
        // Es como se congelará el precio de cada día del periodo (§6.5).
        $this->registrar(45, '2026-01-01');
        $this->registrar(50, '2026-03-01');

        $this->assertSame('45.00', Tarifa::vigenteEn('2026-02-28')->precio);
        $this->assertSame('50.00', Tarifa::vigenteEn('2026-03-01')->precio);
        $this->assertSame('50.00', Tarifa::vigenteEn('2026-12-31')->precio);
        $this->assertNull(Tarifa::vigenteEn('2025-12-31'));
    }

    // --- Permisos ---------------------------------------------------------------------

    public function test_registrar_exige_tarifas_editar(): void
    {
        $this->actuandoComo('tarifas.ver', 'periodos.crear');

        $this->postJson('/api/tarifas', ['precio' => 50, 'vigente_desde' => today()->toDateString()])
            ->assertForbidden();

        $this->assertSame(0, Tarifa::count());
    }

    public function test_consultar_exige_tarifas_ver(): void
    {
        $this->actuandoComo('periodos.ver');

        $this->getJson('/api/tarifas')->assertForbidden();
        $this->getJson('/api/tarifas/vigente')->assertForbidden();
    }

    public function test_exige_sesion(): void
    {
        $this->getJson('/api/tarifas')->assertUnauthorized();
        $this->postJson('/api/tarifas', ['precio' => 50])->assertUnauthorized();
    }

    public function test_el_catalogo_incluye_los_permisos_de_tarifas(): void
    {
        $this->seed(PermisoSeeder::class);

        foreach (['tarifas.ver', 'tarifas.editar'] as $clave) {
            $this->assertTrue(Permiso::where('clave', $clave)->exists(), "Falta {$clave}");
        }
    }
}
