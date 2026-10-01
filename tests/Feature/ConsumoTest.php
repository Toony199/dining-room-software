<?php

namespace Tests\Feature;

use App\Models\DerechoConsumo;
use App\Models\Ficha;
use App\Models\Periodo;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Tarifa;
use App\Models\User;
use Database\Seeders\PermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Validación del consumo en la entrada del comedor (§16).
 *
 * Quien escanea es un colaborador, pero el equipo de la entrada entra con su propia cuenta, igual
 * que el kiosco (§10.1). Las respuestas negativas son 200 con su motivo: para quien atiende la
 * fila, "no pagó hoy" es la respuesta, no un error.
 */
class ConsumoTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes de servicio: el primer día del periodo de prueba. */
    private const LUNES = '2026-03-16';

    private Persona $persona;

    private string $token;

    private Periodo $periodo;

    private Ficha $ficha;

    protected function setUp(): void
    {
        parent::setUp();

        // Se pide y se paga la semana anterior; hoy es el primer día de servicio.
        $this->travelTo(Carbon::parse('2026-03-11')->setTime(9, 0));
        $this->actuandoComo('consumo.validar', 'consumo.ver');
        Tarifa::registrar(50, '2026-01-01');

        $this->persona = Persona::factory()->sinSegundoApellido()->create([
            'nombre' => 'Ana',
            'primer_apellido' => 'Solano',
        ]);
        $this->token = $this->persona->emitirGafete()->qr_token;

        $this->periodo = Periodo::crear('2026-03-16', '2026-03-20', '2026-03-11', '2026-03-13');
        $this->periodo->abrir();
        // Compra lunes y martes.
        $this->ficha = Ficha::generar($this->persona, $this->periodo, $this->periodo->dias()->orderBy('fecha')->pluck('id')->take(2)->all());

        $this->travelTo(Carbon::parse(self::LUNES)->setTime(13, 30));
    }

    private function validar(?string $token = null)
    {
        return $this->postJson('/api/consumo/validar', ['qr_token' => $token ?? $this->token]);
    }

    private function pagar(): void
    {
        $this->ficha->confirmarPago(100, auth()->user());
    }

    // --- Consumo válido (§16.1) ----------------------------------------------------------

    public function test_quien_pago_hoy_puede_pasar_y_el_derecho_queda_usado(): void
    {
        $this->pagar();

        $this->validar()
            ->assertOk()
            ->assertJsonPath('data.resultado', 'PERMITIDO')
            ->assertJsonPath('data.motivo', 'CONSUMO_REGISTRADO')
            ->assertJsonPath('data.mensaje', 'Puede pasar.')
            ->assertJsonPath('data.persona.nombre', 'Ana Solano');

        $derecho = DerechoConsumo::deHoy($this->persona->fresh());
        $this->assertSame(DerechoConsumo::UTILIZADO, $derecho->estado);
        $this->assertSame('13:30', $derecho->utilizado_en->format('H:i'));
        $this->assertSame(auth()->id(), $derecho->validado_por);
    }

    // --- Día ya utilizado (§16.2) ---------------------------------------------------------

    public function test_no_se_puede_comer_dos_veces_el_mismo_dia(): void
    {
        $this->pagar();
        $this->validar()->assertJsonPath('data.resultado', 'PERMITIDO');

        $this->validar()
            ->assertOk()
            ->assertJsonPath('data.resultado', 'RECHAZADO')
            ->assertJsonPath('data.motivo', 'YA_UTILIZADO')
            ->assertJsonPath('data.mensaje', 'Ya registró su comida de hoy a las 13:30.');
    }

    public function test_dos_lectores_a_la_vez_no_gastan_el_mismo_derecho(): void
    {
        // El UPDATE condicionado al estado decide: gana quien llegue primero.
        $this->pagar();
        $derecho = DerechoConsumo::deHoy($this->persona);

        $this->assertTrue($derecho->usar(auth()->user()));
        $this->assertFalse($derecho->usar(auth()->user()));
        $this->assertSame(1, DerechoConsumo::where('estado', DerechoConsumo::UTILIZADO)->count());
    }

    // --- Día no pagado (§16.3) --------------------------------------------------------------

    public function test_sin_pago_confirmado_no_se_pasa(): void
    {
        // §2.2: la ficha sola no da derecho a nada; falta pagarla.
        $this->validar()
            ->assertOk()
            ->assertJsonPath('data.resultado', 'RECHAZADO')
            ->assertJsonPath('data.motivo', 'NO_PAGADO')
            ->assertJsonPath('data.mensaje', 'Hoy no tiene comida pagada.')
            ->assertJsonPath('data.persona.nombre', 'Ana Solano');
    }

    public function test_quien_pago_otros_dias_no_pasa_hoy(): void
    {
        // Pagó lunes y martes; el miércoles no lo compró.
        $this->pagar();
        $this->travelTo(Carbon::parse('2026-03-18')->setTime(13, 0));

        $this->validar()
            ->assertOk()
            ->assertJsonPath('data.motivo', 'NO_PAGADO');
    }

    // --- Día vencido (§16.4, §16.5) -----------------------------------------------------------

    public function test_un_derecho_de_un_dia_pasado_no_sirve_hoy(): void
    {
        // §2.3: no se transfiere ni se recorre. El día pasó.
        $this->pagar();
        $this->travelTo(Carbon::parse('2026-03-17')->setTime(13, 0));
        $this->artisan('comedor:vencer-derechos')->assertSuccessful();

        // El martes sí lo compró: ese sigue vigente.
        $this->validar()->assertJsonPath('data.resultado', 'PERMITIDO');

        // Y el del lunes quedó vencido, no disponible para otro día.
        $lunes = DerechoConsumo::where('ficha_id', $this->ficha->id)
            ->whereHas('diaPeriodo', fn ($dia) => $dia->whereDate('fecha', self::LUNES))
            ->first();
        $this->assertSame(DerechoConsumo::VENCIDO, $lunes->estado);
    }

    public function test_un_derecho_vencido_se_rechaza_con_su_motivo(): void
    {
        $this->pagar();
        DerechoConsumo::deHoy($this->persona)->forceFill(['estado' => DerechoConsumo::VENCIDO])->save();

        $this->validar()
            ->assertOk()
            ->assertJsonPath('data.resultado', 'RECHAZADO')
            ->assertJsonPath('data.motivo', 'VENCIDO');
    }

    public function test_vencer_derechos_no_toca_los_de_hoy_ni_los_ya_usados(): void
    {
        $this->pagar();
        $this->validar()->assertJsonPath('data.resultado', 'PERMITIDO');

        $this->artisan('comedor:vencer-derechos')
            ->expectsOutputToContain('No había derechos por vencer.')
            ->assertSuccessful();

        $this->assertSame(1, DerechoConsumo::where('estado', DerechoConsumo::UTILIZADO)->count());
        $this->assertSame(1, DerechoConsumo::where('estado', DerechoConsumo::VIGENTE)->count());
    }

    // --- Gafete y persona (§11 paso 1) ----------------------------------------------------------

    public function test_rechaza_un_gafete_desconocido_o_reemplazado(): void
    {
        $anterior = $this->token;
        $this->persona->emitirGafete();

        $this->validar('NO-EXISTE')->assertJsonPath('data.motivo', 'GAFETE_DESCONOCIDO');
        $this->validar($anterior)->assertJsonPath('data.motivo', 'GAFETE_REEMPLAZADO');
    }

    public function test_rechaza_a_una_persona_dada_de_baja_aunque_haya_pagado(): void
    {
        // §3.3: una persona inactiva pierde sus capacidades operativas.
        $this->pagar();
        $this->persona->update(['estado' => 'INACTIVO']);

        $this->validar()
            ->assertOk()
            ->assertJsonPath('data.resultado', 'RECHAZADO')
            ->assertJsonPath('data.motivo', 'PERSONA_INACTIVA');

        $this->assertSame(DerechoConsumo::VIGENTE, DerechoConsumo::deHoy($this->persona)->estado);
    }

    // --- Cómo va el servicio -------------------------------------------------------------------

    public function test_el_resumen_del_dia_cuenta_pagados_y_servidos(): void
    {
        $this->pagar();

        $otra = Persona::factory()->create();
        $suFicha = Ficha::generar($otra, $this->periodo, $this->periodo->dias()->orderBy('fecha')->pluck('id')->take(1)->all());
        $suFicha->confirmarPago(50, auth()->user());

        $this->validar()->assertJsonPath('data.resultado', 'PERMITIDO');

        $this->getJson('/api/consumo')
            ->assertOk()
            ->assertJsonPath('data.fecha', self::LUNES)
            ->assertJsonPath('data.pagados', 2)
            ->assertJsonPath('data.servidos', 1)
            ->assertJsonPath('data.por_servir', 1)
            ->assertJsonCount(1, 'data.ultimos')
            ->assertJsonPath('data.ultimos.0.persona', 'Ana Solano');
    }

    // --- Permisos (§5.6) ---------------------------------------------------------------------------

    public function test_validar_exige_su_permiso(): void
    {
        auth()->user()->rol->permisos()->sync([
            Permiso::firstOrCreate(['clave' => 'colaboradores.ver'], ['modulo' => 'colaboradores'])->id,
        ]);

        $this->validar()->assertForbidden();
        $this->getJson('/api/consumo')->assertForbidden();
    }

    public function test_la_cuenta_del_comedor_no_abre_nada_administrativo(): void
    {
        auth()->user()->rol->permisos()->sync(
            Permiso::whereIn('clave', ['consumo.validar', 'consumo.ver'])->pluck('id')->all()
        );

        foreach (['/api/personas', '/api/periodos', '/api/fichas', '/api/tarifas', '/api/usuarios'] as $ruta) {
            $this->getJson($ruta)->assertForbidden();
        }
    }

    public function test_exige_sesion(): void
    {
        auth()->logout();

        $this->validar()->assertUnauthorized();
        $this->getJson('/api/consumo')->assertUnauthorized();
    }

    public function test_el_catalogo_incluye_los_permisos_de_consumo(): void
    {
        $this->seed(PermisoSeeder::class);

        foreach (['consumo.validar', 'consumo.ver'] as $clave) {
            $this->assertTrue(Permiso::where('clave', $clave)->exists(), "Falta {$clave}");
        }
    }

    public function test_guarda_quien_valido_el_consumo(): void
    {
        // §19: las operaciones relevantes dejan rastro de quién las hizo.
        $this->pagar();
        $this->validar()->assertJsonPath('data.resultado', 'PERMITIDO');

        $derecho = DerechoConsumo::deHoy($this->persona);
        $this->assertTrue(User::find($derecho->validado_por)->is(auth()->user()));
    }
}
