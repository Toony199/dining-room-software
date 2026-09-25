<?php

namespace Tests\Feature;

use App\Models\DerechoConsumo;
use App\Models\Ficha;
use App\Models\Pago;
use App\Models\Periodo;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Tarifa;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cobro físico de las fichas (§12, §13, §14).
 *
 * El dinero se recibe fuera del sistema (§2.1): aquí se valida el monto como un POS y se registra
 * lo cobrado. Confirmar el pago es lo único que crea derechos de consumo (§2.2), uno por día
 * pagado (§2.3).
 */
class CobroFichaTest extends TestCase
{
    use RefreshDatabase;

    private const MIERCOLES = '2026-03-11';

    private Persona $persona;

    private Periodo $periodo;

    private Ficha $ficha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MIERCOLES)->setTime(12, 0));
        $this->actuandoComo('fichas.ver', 'fichas.editar', 'pagos.ver', 'pagos.confirmar');

        Tarifa::registrar(50, '2026-01-01');
        $this->persona = Persona::factory()->sinSegundoApellido()->create(['nombre' => 'Ana', 'primer_apellido' => 'Solano']);
        $this->periodo = Periodo::crear('2026-03-16', '2026-03-20', '2026-03-11', '2026-03-13');
        $this->periodo->abrir();

        // Lo que trae la persona a la caja: tres días a $50.
        $this->ficha = Ficha::generar($this->persona, $this->periodo, $this->dias(3));
    }

    /**
     * @return array<int, int>
     */
    private function dias(int $cuantos, int $desde = 0): array
    {
        return $this->periodo->dias()->orderBy('fecha')->pluck('id')->slice($desde, $cuantos)->values()->all();
    }

    // --- Buscar la ficha (§12) -----------------------------------------------------------

    public function test_la_ficha_se_busca_por_su_folio(): void
    {
        // Es lo que la persona trae y lo que el cobrador escanea o teclea; nadie conoce el id.
        $this->getJson("/api/fichas/{$this->ficha->folio}")
            ->assertOk()
            ->assertJsonPath('data.folio', $this->ficha->folio)
            ->assertJsonPath('data.total', '150.00')
            ->assertJsonPath('data.estado', Ficha::PENDIENTE)
            ->assertJsonPath('data.persona.nombre_completo', 'Ana Solano')
            ->assertJsonCount(3, 'data.dias');
    }

    public function test_un_folio_que_no_existe_responde_404(): void
    {
        $this->getJson('/api/fichas/2026-12-9999')->assertNotFound();
    }

    public function test_la_ficha_se_encuentra_escaneando_el_gafete(): void
    {
        // Es lo habitual: como el ticket casi nunca se imprime, la persona llega con su gafete.
        $token = $this->persona->emitirGafete()->qr_token;

        $this->getJson('/api/fichas/por-gafete?qr_token='.$token)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', $this->ficha->folio)
            ->assertJsonPath('data.0.estado', Ficha::PENDIENTE)
            ->assertJsonPath('data.0.total', '150.00');
    }

    public function test_por_gafete_prefiere_la_que_falta_por_pagar(): void
    {
        $token = $this->persona->emitirGafete()->qr_token;
        $this->ficha->confirmarPago(150, auth()->user());

        // Ya pagada: se devuelve igual, para poder decírselo y reimprimirle el comprobante.
        $this->getJson('/api/fichas/por-gafete?qr_token='.$token)
            ->assertOk()
            ->assertJsonPath('data.0.estado', Ficha::PAGADA)
            ->assertJsonPath('data.0.pago.cambio', '0.00');
    }

    public function test_por_gafete_devuelve_las_dos_fichas_de_quien_debe_dos_semanas(): void
    {
        // La regla de una sola ficha es por semana (§17.1): quien no pagó una semana, esa semana
        // no se cerró y pidió la siguiente, llega a la caja con dos. Se muestran ambas, de la más
        // antigua a la más nueva, y el cobrador elige.
        $token = $this->persona->emitirGafete()->qr_token;
        $otraSemana = Periodo::crear('2026-03-23', '2026-03-27', '2026-03-18', '2026-03-20');
        $otraSemana->abrir();
        $segunda = Ficha::generar($this->persona, $otraSemana, $otraSemana->dias()->pluck('id')->take(1)->all());

        $this->getJson('/api/fichas/por-gafete?qr_token='.$token)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.folio', $this->ficha->folio)
            ->assertJsonPath('data.1.folio', $segunda->folio);
    }

    public function test_por_gafete_avisa_cuando_no_hay_nada_que_cobrar(): void
    {
        $otra = Persona::factory()->sinSegundoApellido()->create(['nombre' => 'Beto', 'primer_apellido' => 'Ruiz']);
        $token = $otra->emitirGafete()->qr_token;

        $this->getJson('/api/fichas/por-gafete?qr_token='.$token)
            ->assertStatus(422)
            ->assertJsonPath('errors.qr_token.0', fn ($m) => str_contains($m, 'Beto Ruiz no tiene ninguna ficha por pagar'));
    }

    public function test_por_gafete_rechaza_un_gafete_desconocido_o_reemplazado(): void
    {
        $anterior = $this->persona->emitirGafete()->qr_token;
        $this->persona->emitirGafete();

        $this->getJson('/api/fichas/por-gafete?qr_token=NO-EXISTE')
            ->assertStatus(422)
            ->assertJsonPath('errors.qr_token.0', 'No reconocemos ese gafete.');

        $this->getJson('/api/fichas/por-gafete?qr_token='.$anterior)
            ->assertStatus(422)
            ->assertJsonPath('errors.qr_token.0', fn ($m) => str_contains($m, 'fue reemplazado'));
    }

    public function test_el_listado_filtra_por_estado_y_busca_por_persona(): void
    {
        // Sirve para ver quién falta por pagar antes de que cierre la semana.
        $otra = Persona::factory()->create(['nombre' => 'Beto', 'primer_apellido' => 'Ruiz', 'numero_empleado' => '9001']);
        $pagada = Ficha::generar($otra, $this->periodo, $this->dias(1));
        $pagada->confirmarPago(50, auth()->user());

        $this->getJson('/api/fichas?estado=PENDIENTE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', $this->ficha->folio);

        $this->getJson('/api/fichas?q=9001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', $pagada->folio);
    }

    // --- Ajustar días antes de cobrar (§13) --------------------------------------------------

    public function test_el_cobrador_puede_quitar_dias_y_el_total_baja(): void
    {
        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => $this->dias(2)])
            ->assertOk()
            ->assertJsonCount(2, 'data.dias')
            ->assertJsonPath('data.total', '100.00');
    }

    public function test_el_cobrador_puede_agregar_dias_y_el_total_sube(): void
    {
        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => $this->dias(5)])
            ->assertOk()
            ->assertJsonCount(5, 'data.dias')
            ->assertJsonPath('data.total', '250.00');
    }

    public function test_el_dia_agregado_congela_su_propio_precio(): void
    {
        // §6.4: el precio es por día, y un día del periodo puede tener el suyo.
        $this->periodo->dias()->orderBy('fecha')->get()->last()->update(['precio_aplicado' => 80]);

        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => $this->dias(5)])
            ->assertOk()
            ->assertJsonPath('data.total', '280.00')
            ->assertJsonPath('data.dias.4.precio', '80.00');
    }

    public function test_no_se_pueden_elegir_dias_sin_servicio_ni_de_otra_semana(): void
    {
        $sinServicio = $this->periodo->dias()->orderBy('fecha')->get()->last();
        $sinServicio->update(['disponible' => false, 'es_festivo' => true]);
        $otroPeriodo = Periodo::crear('2026-03-23', '2026-03-27', '2026-03-18', '2026-03-20');

        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => [$sinServicio->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.dias.0', fn ($m) => str_contains($m, 'no tiene servicio'));

        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => [$otroPeriodo->dias()->first()->id]])
            ->assertStatus(422);

        $this->assertSame(3, $this->ficha->fresh()->dias()->count());
    }

    public function test_la_ficha_no_puede_quedarse_sin_dias(): void
    {
        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.dias.0', fn ($m) => str_contains($m, 'al menos un día'));
    }

    public function test_una_ficha_pagada_ya_no_se_edita(): void
    {
        // §17.3: confirmado el pago, la operación queda finalizada.
        $this->ficha->confirmarPago(150, auth()->user());

        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => $this->dias(1)])
            ->assertStatus(422)
            ->assertJsonPath('errors.dias.0', fn ($m) => str_contains($m, 'ya está pagada'));
    }

    // --- Validación del monto (§12.1) ------------------------------------------------------------

    public function test_el_monto_menor_se_rechaza(): void
    {
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 100])
            ->assertStatus(422)
            ->assertJsonPath('errors.monto_recibido.0', fn ($m) => str_contains($m, 'no alcanza'));

        $this->assertSame(Ficha::PENDIENTE, $this->ficha->fresh()->estado);
        $this->assertSame(0, Pago::count());
    }

    public function test_el_monto_exacto_confirma_sin_cambio(): void
    {
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])
            ->assertCreated()
            ->assertJsonPath('data.total_cobrado', '150.00')
            ->assertJsonPath('data.monto_recibido', '150.00')
            ->assertJsonPath('data.cambio', '0.00')
            ->assertJsonPath('data.ficha.estado', Ficha::PAGADA);
    }

    public function test_el_monto_mayor_confirma_y_calcula_el_cambio(): void
    {
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 200])
            ->assertCreated()
            ->assertJsonPath('data.cambio', '50.00');
    }

    public function test_el_ticket_lleva_lo_que_pide_la_spec(): void
    {
        // §14: folio, colaborador, días pagados, total, recibido, cambio, fecha y cobrador.
        $respuesta = $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])
            ->assertCreated()
            ->assertJsonPath('data.ficha.folio', $this->ficha->folio)
            ->assertJsonPath('data.ficha.persona.numero_empleado', $this->persona->numero_empleado)
            ->assertJsonCount(3, 'data.ficha.dias')
            ->assertJsonPath('data.cobrador', fn ($nombre) => str_contains((string) $nombre, self::APELLIDO_DE_SESION));

        $this->assertNotNull($respuesta->json('data.confirmado_en'));
    }

    public function test_una_ficha_no_se_cobra_dos_veces(): void
    {
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])->assertCreated();

        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])
            ->assertStatus(422)
            ->assertJsonPath('errors.monto_recibido.0', fn ($m) => str_contains($m, 'ya estaba pagada'));

        $this->assertSame(1, Pago::count());
    }

    public function test_una_ficha_vencida_ya_no_se_cobra(): void
    {
        $this->periodo->cerrar();

        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])
            ->assertStatus(422)
            ->assertJsonPath('errors.monto_recibido.0', fn ($m) => str_contains($m, 'venció'));

        $this->assertSame(0, DerechoConsumo::count());
    }

    // --- Derechos de consumo (§2.2, §2.3) -----------------------------------------------------------

    public function test_confirmar_el_pago_crea_un_derecho_por_cada_dia(): void
    {
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])->assertCreated();

        $derechos = DerechoConsumo::where('ficha_id', $this->ficha->id)->get();
        $this->assertCount(3, $derechos);
        $this->assertTrue($derechos->every(fn ($d) => $d->estado === DerechoConsumo::VIGENTE));
        $this->assertSame(['50.00', '50.00', '50.00'], $derechos->pluck('precio_pagado')->all());
        $this->assertSame(
            $this->ficha->dias()->pluck('dia_periodo_id')->sort()->values()->all(),
            $derechos->pluck('dia_periodo_id')->sort()->values()->all(),
        );
    }

    public function test_sin_pago_confirmado_no_hay_ningun_derecho(): void
    {
        // §2.2: la ficha sola no da derecho a nada.
        $this->assertSame(0, DerechoConsumo::count());

        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 100])->assertStatus(422);

        $this->assertSame(0, DerechoConsumo::count());
    }

    public function test_los_derechos_reflejan_los_dias_que_quedaron_al_final(): void
    {
        // §13: lo que se cobra es la selección final, no la original.
        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => $this->dias(2)])->assertOk();
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 100])->assertCreated();

        $this->assertSame(2, DerechoConsumo::where('ficha_id', $this->ficha->id)->count());
    }

    // --- Corte de caja ------------------------------------------------------------------------------

    public function test_el_listado_de_pagos_trae_el_corte_del_dia_por_cobrador(): void
    {
        $otra = Persona::factory()->create();
        $segunda = Ficha::generar($otra, $this->periodo, $this->dias(2));

        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 200])->assertCreated();
        $this->postJson("/api/fichas/{$segunda->folio}/pago", ['monto_recibido' => 100])->assertCreated();

        $this->getJson('/api/pagos')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.corte.fichas', 2)
            // Se suma lo cobrado, no lo recibido: el cambio se devolvió y nunca fue de la empresa.
            ->assertJsonPath('meta.corte.total', '250.00')
            ->assertJsonCount(1, 'meta.corte.por_cobrador')
            ->assertJsonPath('meta.corte.por_cobrador.0.total', '250.00')
            ->assertJsonPath('meta.corte.por_cobrador.0.fichas', 2);
    }

    public function test_el_corte_solo_cuenta_el_rango_pedido(): void
    {
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])->assertCreated();

        $this->travelTo(Carbon::parse('2026-03-12')->setTime(9, 0));

        $this->getJson('/api/pagos')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.corte.total', '0.00');

        $this->getJson('/api/pagos?desde=2026-03-11&hasta=2026-03-12')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.corte.total', '150.00');
    }

    // --- Permisos (§5.6) -------------------------------------------------------------------------------

    public function test_cada_operacion_del_cobro_exige_su_permiso(): void
    {
        auth()->user()->rol->permisos()->sync([
            Permiso::firstOrCreate(['clave' => 'colaboradores.ver'], ['modulo' => 'colaboradores'])->id,
        ]);

        $this->getJson('/api/fichas')->assertForbidden();
        $this->getJson('/api/fichas/por-gafete?qr_token=lo-que-sea')->assertForbidden();
        $this->getJson("/api/fichas/{$this->ficha->folio}")->assertForbidden();
        $this->putJson("/api/fichas/{$this->ficha->folio}/dias", ['dias' => $this->dias(1)])->assertForbidden();
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])->assertForbidden();
        $this->getJson('/api/pagos')->assertForbidden();

        $this->assertSame(Ficha::PENDIENTE, $this->ficha->fresh()->estado);
    }

    public function test_exige_sesion(): void
    {
        auth()->logout();

        $this->getJson('/api/fichas')->assertUnauthorized();
        $this->postJson("/api/fichas/{$this->ficha->folio}/pago", ['monto_recibido' => 150])->assertUnauthorized();
    }

    public function test_el_catalogo_incluye_los_permisos_de_fichas(): void
    {
        $this->seed(PermisoSeeder::class);

        foreach (['fichas.ver', 'fichas.editar'] as $clave) {
            $this->assertTrue(Permiso::where('clave', $clave)->exists(), "Falta {$clave}");
        }
    }
}
