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

    public function test_un_precio_de_emergencia_entra_aunque_ya_haya_uno_programado(): void
    {
        // El caso real: hoy rigen $50, para el lunes ya estaba programado $60, y hoy a media
        // mañana hay que cobrar $55. El programado se respeta: entra el lunes como estaba dicho.
        $this->actuandoComo('tarifas.editar', 'tarifas.ver');
        $this->registrar(50, today()->subWeek()->toDateString());
        $programada = $this->registrar(60, today()->addDays(3)->toDateString());

        $this->postJson('/api/tarifas', ['precio' => 55, 'vigente_desde' => today()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.vigente', true)
            // Termina el día antes de que entre el programado: no lo pisa.
            ->assertJsonPath('data.vigente_hasta', today()->addDays(2)->toDateString());

        $this->assertSame('55.00', Tarifa::vigenteEn(today())->precio);
        $this->assertSame('55.00', Tarifa::vigenteEn(today()->addDays(2))->precio);
        $this->assertSame('60.00', Tarifa::vigenteEn(today()->addDays(3))->precio);
        $this->assertSame($programada->id, Tarifa::vigenteEn(today()->addWeek())->id);
    }

    public function test_un_precio_se_puede_meter_entre_dos_programados(): void
    {
        $this->registrar(50, today()->toDateString());
        $this->registrar(70, today()->addDays(10)->toDateString());

        $enMedio = $this->registrar(60, today()->addDays(5)->toDateString());

        $this->assertSame('50.00', Tarifa::vigenteEn(today()->addDays(4))->precio);
        $this->assertSame('60.00', Tarifa::vigenteEn(today()->addDays(5))->precio);
        $this->assertSame('60.00', Tarifa::vigenteEn(today()->addDays(9))->precio);
        $this->assertSame('70.00', Tarifa::vigenteEn(today()->addDays(10))->precio);
        $this->assertSame(today()->addDays(9)->toDateString(), $enMedio->vigente_hasta->toDateString());
    }

    public function test_el_precio_de_hoy_se_puede_reemplazar_el_mismo_dia(): void
    {
        // Ajuste de emergencia: hoy cuesta $50 y a media mañana hay que cobrar $60. Esperar a
        // mañana no es opción.
        $this->actuandoComo('tarifas.editar', 'tarifas.ver');
        $anterior = $this->registrar(50, today()->toDateString());

        $this->postJson('/api/tarifas', ['precio' => 60, 'vigente_desde' => today()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.precio', '60.00')
            ->assertJsonPath('data.vigente', true);

        $this->getJson('/api/tarifas/vigente')->assertJsonPath('data.precio', '60.00');

        // El anterior se queda con el día que de verdad rigió, y deja de ser el vigente.
        $this->assertSame(today()->toDateString(), $anterior->fresh()->vigente_hasta->toDateString());
        $this->assertFalse($anterior->fresh()->estaVigente());
        $this->assertSame(2, Tarifa::count());
    }

    public function test_lo_que_se_arme_despues_del_cambio_usa_el_precio_nuevo(): void
    {
        // Es el efecto que se busca: los periodos que se creen a partir de ahora copian el nuevo
        // precio; lo ya armado conserva el suyo (§6.5).
        $this->registrar(50, today()->toDateString());
        $this->registrar(60, today()->toDateString());

        $this->assertSame('60.00', Tarifa::vigenteEn(today())->precio);
    }

    public function test_una_tarifa_programada_se_puede_corregir_el_mismo_dia_programado(): void
    {
        $this->actuandoComo('tarifas.editar');
        $this->registrar(50, today()->toDateString());
        $programada = $this->registrar(60, today()->addWeek()->toDateString());

        $this->postJson('/api/tarifas', [
            'precio' => 65,
            'vigente_desde' => today()->addWeek()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.programada', true);

        $this->assertSame('65.00', Tarifa::vigenteEn(today()->addWeek())->precio);
        $this->assertSame('50.00', Tarifa::vigenteEn(today())->precio);
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

    // --- Cancelar un precio programado -------------------------------------------------

    public function test_cancelar_un_precio_programado_devuelve_el_tramo_al_anterior(): void
    {
        $this->actuandoComo('tarifas.editar', 'tarifas.ver');
        $vigente = $this->registrar(50, today()->toDateString());
        $programada = $this->registrar(60, today()->addWeek()->toDateString());

        $this->deleteJson("/api/tarifas/{$programada->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Se canceló el precio programado.');

        $this->assertNull(Tarifa::find($programada->id));
        // El anterior vuelve a quedar abierto: la línea de tiempo no se rompe.
        $this->assertNull($vigente->fresh()->vigente_hasta);
        $this->assertSame('50.00', Tarifa::vigenteEn(today()->addMonth())->precio);
    }

    public function test_cancelar_uno_de_en_medio_une_a_sus_vecinos(): void
    {
        $this->actuandoComo('tarifas.editar');
        $vigente = $this->registrar(50, today()->toDateString());
        $enMedio = $this->registrar(60, today()->addDays(5)->toDateString());
        $this->registrar(70, today()->addDays(10)->toDateString());

        $this->deleteJson("/api/tarifas/{$enMedio->id}")->assertOk();

        $this->assertSame(today()->addDays(9)->toDateString(), $vigente->fresh()->vigente_hasta->toDateString());
        $this->assertSame('50.00', Tarifa::vigenteEn(today()->addDays(7))->precio);
        $this->assertSame('70.00', Tarifa::vigenteEn(today()->addDays(10))->precio);
    }

    public function test_no_se_cancela_un_precio_que_ya_rige(): void
    {
        // Los precios que ya rigieron respaldan lo cobrado: no se borran nunca (§6.5).
        $this->actuandoComo('tarifas.editar');
        $vigente = $this->registrar(50, today()->toDateString());
        $anterior = $this->registrar(45, today()->subMonth()->toDateString());

        foreach ([$vigente, $anterior] as $tarifa) {
            $this->deleteJson("/api/tarifas/{$tarifa->id}")
                ->assertStatus(422)
                ->assertJsonPath('errors.tarifa.0', fn ($m) => str_contains($m, 'ya está en vigor'));
        }

        $this->assertSame(2, Tarifa::count());
    }

    public function test_cancelar_exige_tarifas_editar(): void
    {
        $this->actuandoComo('tarifas.ver');
        $programada = $this->registrar(60, today()->addWeek()->toDateString());

        $this->deleteJson("/api/tarifas/{$programada->id}")->assertForbidden();

        $this->assertNotNull(Tarifa::find($programada->id));
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
