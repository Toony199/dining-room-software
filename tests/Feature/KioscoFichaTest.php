<?php

namespace Tests\Feature;

use App\Models\Ficha;
use App\Models\Periodo;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Tarifa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kiosco y generación de fichas (§10, §11, §17).
 *
 * Frente al kiosco hay un colaborador que se identifica con el QR de su gafete, pero el equipo
 * entra con su propia cuenta: la del rol Kiosco, que solo tiene `kiosco.operar` y por eso no abre
 * ningún módulo administrativo (§10.1). La ficha nace PENDIENTE y por sí sola no da derecho a comer
 * (§2.2); vence si el periodo cierra sin pago (§8.3).
 */
class KioscoFichaTest extends TestCase
{
    use RefreshDatabase;

    /** Miércoles dentro de la ventana del periodo de prueba. */
    private const MIERCOLES = '2026-03-11';

    private Persona $persona;

    private string $token;

    private Periodo $periodo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MIERCOLES)->setTime(9, 0));
        $this->actuandoComo('kiosco.operar');
        Tarifa::registrar(50, '2026-01-01');

        $this->persona = Persona::factory()->create(['nombre' => 'Ana', 'primer_apellido' => 'Solano']);
        $this->token = $this->persona->emitirGafete()->qr_token;

        // Servicio del 16 al 20; se pide y se paga del 11 al 13 (§7, decisión del negocio).
        $this->periodo = Periodo::crear('2026-03-16', '2026-03-20', '2026-03-11', '2026-03-13');
        $this->periodo->abrir();
    }

    private function identificar(?string $token = null)
    {
        return $this->postJson('/api/kiosco/identificar', ['qr_token' => $token ?? $this->token]);
    }

    /**
     * @param  array<int, int>|null  $dias
     */
    private function generar(?array $dias = null, ?string $token = null)
    {
        return $this->postJson('/api/kiosco/fichas', [
            'qr_token' => $token ?? $this->token,
            'dias' => $dias ?? $this->periodo->dias()->pluck('id')->take(3)->all(),
        ]);
    }

    // --- Identificación (§11 paso 1 y 2) ---------------------------------------------------

    public function test_identifica_a_la_persona_por_su_gafete(): void
    {
        $this->identificar()
            ->assertOk()
            ->assertJsonPath('data.persona.nombre', 'Ana Solano')
            ->assertJsonPath('data.persona.numero_empleado', $this->persona->numero_empleado)
            ->assertJsonPath('data.periodo.id', $this->periodo->id)
            ->assertJsonCount(5, 'data.periodo.dias')
            ->assertJsonPath('data.ficha', null);
    }

    public function test_los_dias_sin_servicio_viajan_marcados_para_mostrarse_bloqueados(): void
    {
        // §11 paso 3: los festivos aparecen bloqueados, no escondidos.
        $dia = $this->periodo->dias()->first();
        $dia->update(['disponible' => false, 'es_festivo' => true, 'motivo_indisponibilidad' => 'Día festivo']);

        $this->identificar()
            ->assertOk()
            ->assertJsonPath('data.periodo.dias.0.disponible', false)
            ->assertJsonPath('data.periodo.dias.0.motivo_indisponibilidad', 'Día festivo')
            ->assertJsonPath('data.periodo.dias.1.disponible', true);
    }

    public function test_no_expone_nada_de_otras_personas(): void
    {
        $otra = Persona::factory()->create(['nombre' => 'Beto', 'primer_apellido' => 'Ruiz']);
        $otra->emitirGafete();

        $respuesta = $this->identificar()->assertOk();

        $this->assertStringNotContainsString('Beto', $respuesta->getContent());
        $this->assertStringNotContainsString($otra->numero_empleado, $respuesta->getContent());
        // Ni el token del propio gafete vuelve al kiosco: se manda, no se recibe.
        $this->assertStringNotContainsString($this->token, $respuesta->getContent());
    }

    public function test_el_kiosco_exige_su_propia_sesion(): void
    {
        // Ya no es público: el equipo del pasillo entra con la cuenta del rol Kiosco.
        auth()->logout();

        $this->identificar()->assertUnauthorized();
        $this->generar()->assertUnauthorized();
    }

    public function test_una_cuenta_sin_el_permiso_no_opera_el_kiosco(): void
    {
        // Se le quita el permiso al rol de la sesión: los permisos se leen del rol en cada
        // petición, así que el cambio corta el acceso de inmediato (§5.6).
        auth()->user()->rol->permisos()->sync([
            Permiso::firstOrCreate(['clave' => 'colaboradores.ver'], ['modulo' => 'colaboradores'])->id,
        ]);

        $this->identificar()->assertForbidden();
        $this->generar()->assertForbidden();

        $this->assertSame(0, Ficha::count());
    }

    public function test_la_cuenta_del_kiosco_no_abre_nada_administrativo(): void
    {
        // §10.1: el kiosco no expone el sistema administrativo. Su rol solo trae `kiosco.operar`,
        // así que el backend le cierra todo lo demás aunque alguien teclee la dirección.
        foreach (['/api/personas', '/api/periodos', '/api/usuarios', '/api/roles', '/api/tarifas'] as $ruta) {
            $this->getJson($ruta)->assertForbidden();
        }
    }

    public function test_rechaza_un_gafete_desconocido(): void
    {
        $this->identificar('TOKEN-QUE-NO-EXISTE')
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'No reconocimos ese gafete'));
    }

    public function test_rechaza_un_gafete_reemplazado(): void
    {
        // §4.3: reponer invalida el anterior de inmediato.
        $anterior = $this->token;
        $this->persona->emitirGafete();

        $this->identificar($anterior)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'fue reemplazado'));
    }

    public function test_rechaza_a_una_persona_dada_de_baja(): void
    {
        // §3.3: una persona inactiva pierde sus capacidades operativas.
        $this->persona->update(['estado' => 'INACTIVO']);

        $this->identificar()
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dado de baja'));
    }

    public function test_fuera_de_la_ventana_no_hay_nada_que_pedir(): void
    {
        $this->travelTo(Carbon::parse('2026-03-16')->setTime(9, 0));

        $this->identificar()
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Hoy no se pueden generar fichas'));
    }

    public function test_un_periodo_sin_abrir_no_sirve_aunque_la_ventana_corra(): void
    {
        Ficha::query()->delete();
        $this->periodo->forceFill(['estado' => Periodo::BORRADOR])->save();

        $this->identificar()->assertStatus(422);
    }

    public function test_muestra_la_ficha_que_ya_tiene(): void
    {
        // §17.1: una sola ficha válida por persona y periodo; la pantalla la enseña en vez de
        // dejar elegir otra vez.
        $dias = $this->periodo->dias()->pluck('id')->take(2)->all();
        $ficha = Ficha::generar($this->persona, $this->periodo, $dias);

        $this->identificar()
            ->assertOk()
            ->assertJsonPath('data.ficha.folio', $ficha->folio)
            ->assertJsonPath('data.ficha.estado', Ficha::PENDIENTE)
            ->assertJsonCount(2, 'data.ficha.dias');
    }

    // --- Generación (§11 paso 3 y 4) ------------------------------------------------------

    public function test_genera_la_ficha_con_los_dias_elegidos_y_su_total(): void
    {
        $dias = $this->periodo->dias()->pluck('id')->take(3)->all();

        $this->generar($dias)
            ->assertCreated()
            ->assertJsonPath('data.estado', Ficha::PENDIENTE)
            ->assertJsonPath('data.total', '150.00')
            ->assertJsonCount(3, 'data.dias')
            ->assertJsonPath('data.dias.0.precio', '50.00')
            ->assertJsonPath('data.persona.numero_empleado', $this->persona->numero_empleado);
    }

    public function test_el_total_suma_el_precio_de_cada_dia(): void
    {
        // §6.4: el precio es por día, y un día puede tener el suyo (§8.1).
        $dias = $this->periodo->dias()->orderBy('fecha')->get();
        $dias->first()->update(['precio_aplicado' => 35]);

        $this->generar([$dias[0]->id, $dias[1]->id])
            ->assertCreated()
            ->assertJsonPath('data.total', '85.00');
    }

    public function test_el_folio_lleva_el_ano_la_semana_y_un_consecutivo(): void
    {
        $primera = $this->generar()->assertCreated()->json('data.folio');

        $otra = Persona::factory()->create();
        $segunda = $this->generar(null, $otra->emitirGafete()->qr_token)->assertCreated()->json('data.folio');

        $this->assertSame('2026-12-0001', $primera);   // semana ISO del 16 al 20 de marzo de 2026
        $this->assertSame('2026-12-0002', $segunda);
    }

    public function test_no_deja_elegir_un_dia_sin_servicio(): void
    {
        $dia = $this->periodo->dias()->first();
        $dia->update(['disponible' => false, 'es_festivo' => true]);

        $this->generar([$dia->id])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ya no está disponible'));

        $this->assertSame(0, Ficha::count());
    }

    public function test_no_deja_elegir_dias_de_otro_periodo(): void
    {
        $otro = Periodo::crear('2026-03-23', '2026-03-27', '2026-03-18', '2026-03-20');

        $this->generar([$otro->dias()->first()->id])->assertStatus(422);

        $this->assertSame(0, Ficha::count());
    }

    public function test_exige_elegir_al_menos_un_dia(): void
    {
        $this->postJson('/api/kiosco/fichas', ['qr_token' => $this->token, 'dias' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.dias.0', 'Elige al menos un día para comer.');
    }

    public function test_una_persona_no_puede_generar_dos_fichas_del_mismo_periodo(): void
    {
        // §17.1: evita varios tickets de la misma semana.
        $this->generar()->assertCreated();

        $this->generar()
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Ya tienes una ficha de esta semana'));

        $this->assertSame(1, Ficha::count());
    }

    public function test_puede_generar_otra_si_la_anterior_vencio(): void
    {
        // Una ficha vencida ya no vale para nada, así que no estorba.
        $anterior = Ficha::generar($this->persona, $this->periodo, $this->periodo->dias()->pluck('id')->take(1)->all());
        $anterior->forceFill(['estado' => Ficha::VENCIDA])->save();

        $this->generar()->assertCreated();

        $this->assertSame(1, Ficha::query()->valida()->count());
    }

    public function test_no_genera_ficha_fuera_de_la_ventana(): void
    {
        // §17.4: terminado el rango de generación no se generan nuevas fichas.
        $this->travelTo(Carbon::parse('2026-03-14')->setTime(9, 0));

        $this->generar()->assertStatus(422);

        $this->assertSame(0, Ficha::count());
    }

    public function test_no_genera_ficha_una_persona_dada_de_baja(): void
    {
        $this->persona->update(['estado' => 'INACTIVO']);

        $this->generar()->assertStatus(422);

        $this->assertSame(0, Ficha::count());
    }

    // --- Cierre del periodo (§8.3, §17.4) ----------------------------------------------------

    public function test_cerrar_el_periodo_vence_las_fichas_pendientes(): void
    {
        $pendiente = Ficha::generar($this->persona, $this->periodo, $this->periodo->dias()->pluck('id')->take(1)->all());

        $otra = Persona::factory()->create();
        $pagada = Ficha::generar($otra, $this->periodo, $this->periodo->dias()->pluck('id')->take(1)->all());
        $pagada->forceFill(['estado' => Ficha::PAGADA])->save();

        $this->periodo->cerrar();

        $this->assertSame(Ficha::VENCIDA, $pendiente->fresh()->estado);
        // Lo pagado no se toca: ya generó su derecho.
        $this->assertSame(Ficha::PAGADA, $pagada->fresh()->estado);
    }

    public function test_reabrir_no_revive_las_fichas_vencidas(): void
    {
        $ficha = Ficha::generar($this->persona, $this->periodo, $this->periodo->dias()->pluck('id')->take(1)->all());
        $this->periodo->cerrar();
        $this->periodo->reabrir();

        $this->assertSame(Ficha::VENCIDA, $ficha->fresh()->estado);
        // Y como la vencida no cuenta, puede generar otra mientras la ventana siga corriendo.
        $this->generar()->assertCreated();
    }
}
