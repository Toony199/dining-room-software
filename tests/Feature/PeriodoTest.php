<?php

namespace Tests\Feature;

use App\Models\DiaPeriodo;
use App\Models\Periodo;
use App\Models\Tarifa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Periodos semanales de servicio (§6, §7, §8, §9).
 *
 * El periodo cubre la semana siguiente, de lunes a viernes, y su ventana para generar fichas y
 * pagar corre de miércoles a viernes de la semana anterior, para que al llegar el lunes del
 * servicio ya se sepan las porciones. La spec pedía la ventana dentro del periodo (§7.2); la
 * decisión del negocio la relajó a "antes del periodo o dentro de él".
 */
class PeriodoTest extends TestCase
{
    use RefreshDatabase;

    /** Un martes cualquiera, para que "la semana siguiente" no dependa del día en que se corran. */
    private const MARTES = '2026-03-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MARTES)->setTime(9, 0));
        Tarifa::registrar(50, '2026-01-01');
    }

    /** El periodo de la semana siguiente al martes de prueba: 16 al 20 de marzo de 2026. */
    private function periodoDeLaSemanaSiguiente(): Periodo
    {
        return Periodo::generarSemana(Periodo::lunesDeLaSemanaSiguiente());
    }

    // --- Generación ----------------------------------------------------------------------

    public function test_genera_la_semana_siguiente_de_lunes_a_viernes_con_su_ventana(): void
    {
        $this->actuandoComo('periodos.crear');

        $this->postJson('/api/periodos/generar-siguiente')
            ->assertCreated()
            ->assertJsonPath('data.fecha_inicio', '2026-03-16')   // lunes siguiente
            ->assertJsonPath('data.fecha_fin', '2026-03-20')      // viernes siguiente
            ->assertJsonPath('data.ventana_inicio', '2026-03-11') // miércoles de esta semana
            ->assertJsonPath('data.ventana_fin', '2026-03-13')    // viernes de esta semana
            ->assertJsonPath('data.estado', Periodo::BORRADOR)
            ->assertJsonCount(5, 'data.dias')
            ->assertJsonPath('data.dias.0.dia_semana', 1)
            ->assertJsonPath('data.dias.4.dia_semana', 5);
    }

    public function test_generar_dos_veces_no_duplica_el_periodo(): void
    {
        // §6.1: la generación automática debe ser idempotente.
        $this->actuandoComo('periodos.crear');

        $primera = $this->postJson('/api/periodos/generar-siguiente')->assertCreated();
        $this->postJson('/api/periodos/generar-siguiente')
            ->assertOk()
            ->assertJsonPath('data.id', $primera->json('data.id'));

        $this->assertSame(1, Periodo::count());
        $this->assertSame(5, DiaPeriodo::count());
    }

    public function test_congela_el_precio_que_rige_cada_dia(): void
    {
        // §6.5: si el precio sube a media semana, cada día conserva el suyo.
        Tarifa::registrar(60, '2026-03-18');
        $this->actuandoComo('periodos.crear');

        $dias = $this->postJson('/api/periodos/generar-siguiente')->assertCreated()->json('data.dias');

        $this->assertSame(['50.00', '50.00', '60.00', '60.00', '60.00'], array_column($dias, 'precio_aplicado'));
    }

    public function test_no_se_puede_crear_un_periodo_sin_precio_registrado(): void
    {
        Tarifa::query()->delete();
        $this->actuandoComo('periodos.crear');

        $this->postJson('/api/periodos/generar-siguiente')
            ->assertStatus(422)
            ->assertJsonPath('errors.periodo.0', fn ($mensaje) => str_contains($mensaje, 'No hay un precio registrado'));

        $this->assertSame(0, Periodo::count());
    }

    public function test_crea_un_periodo_con_fechas_propias(): void
    {
        $this->actuandoComo('periodos.crear');

        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-16',
            'fecha_fin' => '2026-03-21',       // esta semana sí incluye sábado
            'ventana_inicio' => '2026-03-11',
            'ventana_fin' => '2026-03-13',
        ])
            ->assertCreated()
            ->assertJsonCount(6, 'data.dias')
            ->assertJsonPath('data.dias.5.dia_semana', 6);
    }

    // --- Ventana (§7.2 y la decisión que la relajó) ----------------------------------------

    public function test_acepta_la_ventana_antes_del_periodo_y_tambien_dentro(): void
    {
        $this->actuandoComo('periodos.crear');

        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-16',
            'fecha_fin' => '2026-03-20',
            'ventana_inicio' => '2026-03-17',   // dentro del propio periodo, como el ejemplo de §7
            'ventana_fin' => '2026-03-17',
        ])->assertCreated();
    }

    public function test_rechaza_una_ventana_que_invade_o_pasa_el_periodo(): void
    {
        $this->actuandoComo('periodos.crear');

        // Empieza antes del periodo pero termina dentro: quedaría a medio caballo.
        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-16',
            'fecha_fin' => '2026-03-20',
            'ventana_inicio' => '2026-03-13',
            'ventana_fin' => '2026-03-17',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.ventana_inicio.0', fn ($m) => str_contains($m, 'antes de que empiece el periodo'));

        // Termina después del servicio: no tiene sentido pagar comida ya servida.
        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-16',
            'fecha_fin' => '2026-03-20',
            'ventana_inicio' => '2026-03-18',
            'ventana_fin' => '2026-03-25',
        ])->assertStatus(422);

        $this->assertSame(0, Periodo::count());
    }

    public function test_rechaza_fechas_al_reves(): void
    {
        $this->actuandoComo('periodos.crear');

        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-20',
            'fecha_fin' => '2026-03-16',
            'ventana_inicio' => '2026-03-11',
            'ventana_fin' => '2026-03-13',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_fin.0', 'El periodo no puede terminar antes de empezar.');

        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-16',
            'fecha_fin' => '2026-03-20',
            'ventana_inicio' => '2026-03-13',
            'ventana_fin' => '2026-03-11',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.ventana_fin.0', 'La ventana no puede terminar antes de empezar.');
    }

    public function test_rechaza_un_periodo_que_se_traslapa_con_otro(): void
    {
        // Dos periodos sobre el mismo día volverían ambiguo a cuál pertenece una ficha.
        $this->actuandoComo('periodos.crear');
        $this->periodoDeLaSemanaSiguiente();

        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-03-18',
            'fecha_fin' => '2026-03-24',
            'ventana_inicio' => '2026-03-11',
            'ventana_fin' => '2026-03-13',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.fecha_inicio.0', fn ($m) => str_contains($m, 'Ya existe un periodo'));
    }

    public function test_la_ventana_se_puede_mover_mientras_este_en_borrador(): void
    {
        $this->actuandoComo('periodos.editar');
        $periodo = $this->periodoDeLaSemanaSiguiente();

        $this->putJson("/api/periodos/{$periodo->id}", [
            'ventana_inicio' => '2026-03-12',
            'ventana_fin' => '2026-03-13',
        ])
            ->assertOk()
            ->assertJsonPath('data.ventana_inicio', '2026-03-12');

        $periodo->abrir();

        $this->putJson("/api/periodos/{$periodo->id}", [
            'ventana_inicio' => '2026-03-11',
            'ventana_fin' => '2026-03-13',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.periodo.0', 'Solo se puede configurar un periodo en borrador.');
    }

    // --- Días (§6.3) ------------------------------------------------------------------------

    public function test_un_dia_se_puede_marcar_como_festivo_y_fuera_de_servicio(): void
    {
        $this->actuandoComo('periodos.editar');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $dia = $periodo->dias()->first();

        $this->putJson("/api/periodos/{$periodo->id}/dias/{$dia->id}", [
            'disponible' => false,
            'es_festivo' => true,
            'motivo_indisponibilidad' => 'Natalicio de Benito Juárez',
            'precio_aplicado' => $dia->precio_aplicado,
        ])
            ->assertOk()
            ->assertJsonPath('data.dias.0.disponible', false)
            ->assertJsonPath('data.dias.0.es_festivo', true)
            ->assertJsonPath('data.dias.0.motivo_indisponibilidad', 'Natalicio de Benito Juárez');
    }

    public function test_el_precio_de_un_dia_se_puede_ajustar_en_borrador(): void
    {
        // §8.1 permite modificar precios mientras el periodo se configura.
        $this->actuandoComo('periodos.editar');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $dia = $periodo->dias()->first();

        $this->putJson("/api/periodos/{$periodo->id}/dias/{$dia->id}", [
            'disponible' => true,
            'es_festivo' => false,
            'precio_aplicado' => 35,
        ])
            ->assertOk()
            ->assertJsonPath('data.dias.0.precio_aplicado', '35.00');
    }

    public function test_un_dia_disponible_no_puede_traer_motivo_de_ausencia(): void
    {
        $this->actuandoComo('periodos.editar');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $dia = $periodo->dias()->first();

        $this->putJson("/api/periodos/{$periodo->id}/dias/{$dia->id}", [
            'disponible' => true,
            'es_festivo' => false,
            'motivo_indisponibilidad' => 'Festivo',
            'precio_aplicado' => 50,
        ])->assertStatus(422)->assertJsonValidationErrorFor('motivo_indisponibilidad');
    }

    public function test_los_dias_no_se_tocan_con_el_periodo_ya_abierto(): void
    {
        $this->actuandoComo('periodos.editar');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $dia = $periodo->dias()->first();
        $periodo->abrir();

        $this->putJson("/api/periodos/{$periodo->id}/dias/{$dia->id}", [
            'disponible' => false,
            'es_festivo' => true,
            'precio_aplicado' => 50,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.dia.0', 'Solo se pueden cambiar los días de un periodo en borrador.');
    }

    public function test_un_dia_de_otro_periodo_responde_404(): void
    {
        $this->actuandoComo('periodos.editar');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $ajeno = Periodo::crear('2026-03-23', '2026-03-27', '2026-03-18', '2026-03-20')->dias()->first();

        $this->putJson("/api/periodos/{$periodo->id}/dias/{$ajeno->id}", [
            'disponible' => true,
            'es_festivo' => false,
            'precio_aplicado' => 50,
        ])->assertNotFound();
    }

    // --- Estados (§8) --------------------------------------------------------------------------

    public function test_abrir_pone_el_periodo_a_disposicion(): void
    {
        $this->actuandoComo('periodos.abrir');
        $periodo = $this->periodoDeLaSemanaSiguiente();

        $this->patchJson("/api/periodos/{$periodo->id}/abrir")
            ->assertOk()
            ->assertJsonPath('data.estado', Periodo::ABIERTO);
    }

    public function test_no_se_abre_un_periodo_sin_dias_disponibles(): void
    {
        // Sin días que elegir, abrirlo solo produciría fichas vacías.
        $this->actuandoComo('periodos.abrir');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $periodo->dias()->update(['disponible' => false]);

        $this->patchJson("/api/periodos/{$periodo->id}/abrir")
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', fn ($m) => str_contains($m, 'ningún día disponible'));
    }

    public function test_solo_se_abre_desde_borrador(): void
    {
        $this->actuandoComo('periodos.abrir');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $periodo->abrir();

        $this->patchJson("/api/periodos/{$periodo->id}/abrir")
            ->assertStatus(422)
            ->assertJsonPath('errors.estado.0', 'Solo se puede abrir un periodo en borrador.');
    }

    public function test_cerrar_y_reabrir_siguen_el_camino_de_los_estados(): void
    {
        $this->actuandoComo('periodos.abrir', 'periodos.cerrar', 'periodos.reabrir');
        $periodo = $this->periodoDeLaSemanaSiguiente();

        // Cerrar exige estar abierto.
        $this->patchJson("/api/periodos/{$periodo->id}/cerrar")->assertStatus(422);

        $this->patchJson("/api/periodos/{$periodo->id}/abrir")->assertOk();
        $this->patchJson("/api/periodos/{$periodo->id}/cerrar")
            ->assertOk()
            ->assertJsonPath('data.estado', Periodo::PAGO_CERRADO);

        $this->patchJson("/api/periodos/{$periodo->id}/reabrir")
            ->assertOk()
            ->assertJsonPath('data.estado', Periodo::ABIERTO);

        // Reabrir solo desde pago cerrado.
        $this->patchJson("/api/periodos/{$periodo->id}/reabrir")->assertStatus(422);
    }

    // --- La ventana manda sobre las fichas (§17.4) ------------------------------------------------

    public function test_solo_admite_fichas_con_el_periodo_abierto_y_la_ventana_corriendo(): void
    {
        $this->actuandoComo('periodos.ver', 'periodos.abrir');
        $periodo = $this->periodoDeLaSemanaSiguiente();   // ventana 11 al 13 de marzo

        // En borrador, aunque la ventana esté corriendo.
        $this->travelTo(Carbon::parse('2026-03-12')->setTime(9, 0));
        $this->getJson("/api/periodos/{$periodo->id}")->assertJsonPath('data.admite_fichas', false);

        $periodo->abrir();
        $this->getJson("/api/periodos/{$periodo->id}")
            ->assertJsonPath('data.admite_fichas', true)
            ->assertJsonPath('data.ventana_vigente', true)
            ->assertJsonPath('data.ventana_vencida', false);

        // Pasada la ventana ya no, aunque nadie haya cerrado el periodo: el cierre es manual, pero
        // la fecha manda para generar fichas.
        $this->travelTo(Carbon::parse('2026-03-16')->setTime(9, 0));
        $this->getJson("/api/periodos/{$periodo->id}")
            ->assertJsonPath('data.estado', Periodo::ABIERTO)
            ->assertJsonPath('data.admite_fichas', false)
            ->assertJsonPath('data.ventana_vencida', true);
    }

    // --- Consulta ----------------------------------------------------------------------------------

    public function test_el_listado_va_del_mas_reciente_al_mas_antiguo_y_cuenta_los_dias_disponibles(): void
    {
        $this->actuandoComo('periodos.ver');
        $anterior = Periodo::crear('2026-03-02', '2026-03-06', '2026-02-25', '2026-02-27');
        $siguiente = $this->periodoDeLaSemanaSiguiente();
        $siguiente->dias()->limit(2)->update(['disponible' => false]);

        $this->getJson('/api/periodos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $siguiente->id)
            ->assertJsonPath('data.0.dias_disponibles', 3)
            ->assertJsonPath('data.1.id', $anterior->id)
            ->assertJsonPath('data.1.dias_disponibles', 5);
    }

    public function test_el_listado_se_puede_filtrar_por_estado(): void
    {
        $this->actuandoComo('periodos.ver');
        $borrador = $this->periodoDeLaSemanaSiguiente();
        $abierto = Periodo::crear('2026-03-23', '2026-03-27', '2026-03-18', '2026-03-20');
        $abierto->abrir();

        $this->getJson('/api/periodos?estado=ABIERTO')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $abierto->id);

        $this->getJson('/api/periodos?estado=BORRADOR')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $borrador->id);
    }

    // --- Permisos (§9) --------------------------------------------------------------------------------

    public function test_cada_operacion_exige_su_permiso(): void
    {
        $this->actuandoComo('periodos.ver');
        $periodo = $this->periodoDeLaSemanaSiguiente();
        $dia = $periodo->dias()->first();

        $this->postJson('/api/periodos/generar-siguiente')->assertForbidden();
        $this->postJson('/api/periodos', [
            'fecha_inicio' => '2026-04-06',
            'fecha_fin' => '2026-04-10',
            'ventana_inicio' => '2026-04-01',
            'ventana_fin' => '2026-04-03',
        ])->assertForbidden();
        $this->putJson("/api/periodos/{$periodo->id}", ['ventana_inicio' => '2026-03-11', 'ventana_fin' => '2026-03-12'])->assertForbidden();
        $this->putJson("/api/periodos/{$periodo->id}/dias/{$dia->id}", ['disponible' => false, 'es_festivo' => true, 'precio_aplicado' => 50])->assertForbidden();
        $this->patchJson("/api/periodos/{$periodo->id}/abrir")->assertForbidden();
        $this->patchJson("/api/periodos/{$periodo->id}/cerrar")->assertForbidden();
        $this->patchJson("/api/periodos/{$periodo->id}/reabrir")->assertForbidden();

        $this->assertTrue($periodo->fresh()->estaEnBorrador());
    }

    public function test_consultar_exige_periodos_ver(): void
    {
        $this->actuandoComo('tarifas.ver');

        $this->getJson('/api/periodos')->assertForbidden();
    }

    public function test_exige_sesion(): void
    {
        $this->getJson('/api/periodos')->assertUnauthorized();
        $this->postJson('/api/periodos/generar-siguiente')->assertUnauthorized();
    }

    // --- El comando de los martes ----------------------------------------------------------------------

    public function test_el_comando_crea_el_periodo_y_no_lo_duplica(): void
    {
        $this->artisan('comedor:generar-periodo')
            ->expectsOutputToContain('creado en borrador')
            ->assertSuccessful();

        $this->artisan('comedor:generar-periodo')
            ->expectsOutputToContain('ya existe')
            ->assertSuccessful();

        $this->assertSame(1, Periodo::count());
        $this->assertTrue(Periodo::first()->generado_auto);
    }

    public function test_el_comando_avisa_si_falta_el_precio(): void
    {
        Tarifa::query()->delete();

        $this->artisan('comedor:generar-periodo')
            ->expectsOutputToContain('No hay un precio registrado')
            ->assertFailed();
    }
}
