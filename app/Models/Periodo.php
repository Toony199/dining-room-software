<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Periodo semanal de servicio (§6, §8).
 *
 * Cubre una semana de comedor, normalmente de lunes a viernes, y se genera automáticamente en
 * BORRADOR (§6.1). El gestor marca festivos, ajusta la ventana y lo abre.
 *
 * La ventana es el rango de fechas —sin horas (§7.3)— en el que se generan fichas y se paga. Aquí
 * corre *antes* del periodo: el martes de la semana previa aparece el borrador de la semana
 * siguiente y la ventana va del miércoles al viernes de esa semana previa, para que al llegar el
 * lunes del servicio ya se sepan las porciones. La spec (§7.2) pedía la ventana dentro del periodo;
 * la decisión del negocio la relajó a "antes del periodo o dentro de él".
 *
 * Estados (§8): BORRADOR se configura; ABIERTO admite fichas y cobro; PAGO_CERRADO cierra la
 * generación y vence las pendientes; CONSOLIDADO es el reporte ya generado, que llegará con el
 * módulo de reportes. El cierre es una acción de una persona, no del reloj: lo que sí depende de la
 * fecha es generar fichas, que solo se puede dentro de la ventana (§17.4).
 */
#[Fillable(['fecha_inicio', 'fecha_fin', 'ventana_inicio', 'ventana_fin', 'estado', 'generado_auto'])]
class Periodo extends Model
{
    public const BORRADOR = 'BORRADOR';

    public const ABIERTO = 'ABIERTO';

    public const PAGO_CERRADO = 'PAGO_CERRADO';

    public const CONSOLIDADO = 'CONSOLIDADO';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => self::BORRADOR,
        'generado_auto' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'ventana_inicio' => 'date',
            'ventana_fin' => 'date',
            'generado_auto' => 'boolean',
        ];
    }

    public function dias(): HasMany
    {
        return $this->hasMany(DiaPeriodo::class, 'periodo_id')->orderBy('fecha');
    }

    // --- Creación ---------------------------------------------------------------------

    /**
     * Crea el periodo de la semana que empieza en `$lunes`, con sus días y el precio de cada uno ya
     * congelado.
     *
     * Es idempotente (§6.1): si esa semana ya existe, devuelve la que hay sin tocarla. Así el
     * comando de los martes puede ejecutarse dos veces sin duplicar nada.
     *
     * @throws RuntimeException si no hay un precio registrado para algún día de la semana.
     */
    public static function generarSemana(Carbon|string $lunes, bool $automatico = false): self
    {
        $inicio = Carbon::parse($lunes)->startOfDay();
        $dias = (int) config('comedor.periodos.dias_de_servicio', 5);
        $fin = $inicio->copy()->addDays($dias - 1);

        return self::query()->whereDate('fecha_inicio', $inicio)->whereDate('fecha_fin', $fin)->first()
            ?? self::crear(
                $inicio,
                $fin,
                $inicio->copy()->addDays((int) config('comedor.periodos.ventana_inicio_offset', -5)),
                $inicio->copy()->addDays((int) config('comedor.periodos.ventana_fin_offset', -3)),
                $automatico,
            );
    }

    /**
     * El lunes de la semana siguiente a la de `$referencia` (hoy, si no se dice otra).
     */
    public static function lunesDeLaSemanaSiguiente(Carbon|string|null $referencia = null): Carbon
    {
        return Carbon::parse($referencia ?? today())->startOfWeek(Carbon::MONDAY)->addWeek();
    }

    /**
     * Crea el periodo y sus días. Cada día guarda el precio que rige *ese* día según el catálogo
     * (§6.5): si hay un precio programado a media semana, cada día queda con el suyo.
     *
     * @throws RuntimeException si algún día de la semana no tiene precio en el catálogo.
     */
    public static function crear(
        Carbon|string $inicio,
        Carbon|string $fin,
        Carbon|string $ventanaInicio,
        Carbon|string $ventanaFin,
        bool $automatico = false,
    ): self {
        $inicio = Carbon::parse($inicio)->startOfDay();
        $fin = Carbon::parse($fin)->startOfDay();

        return DB::transaction(function () use ($inicio, $fin, $ventanaInicio, $ventanaFin, $automatico) {
            $periodo = self::create([
                'fecha_inicio' => $inicio,
                'fecha_fin' => $fin,
                'ventana_inicio' => Carbon::parse($ventanaInicio)->startOfDay(),
                'ventana_fin' => Carbon::parse($ventanaFin)->startOfDay(),
                'estado' => self::BORRADOR,
                'generado_auto' => $automatico,
            ]);

            for ($dia = $inicio->copy(); $dia->lte($fin); $dia->addDay()) {
                $tarifa = Tarifa::vigenteEn($dia);

                if (! $tarifa) {
                    throw new RuntimeException(
                        'No hay un precio registrado para el '.$dia->format('d/m/Y').'. Registra el precio por día antes de crear el periodo.'
                    );
                }

                $periodo->dias()->create([
                    'fecha' => $dia->copy(),
                    'dia_semana' => $dia->dayOfWeekIso,
                    'disponible' => true,
                    'es_festivo' => false,
                    'precio_aplicado' => $tarifa->precio,
                ]);
            }

            return $periodo;
        });
    }

    /**
     * Periodos cuyas fechas de servicio se cruzan con el rango dado. Dos periodos sobre el mismo
     * día volverían ambiguo a cuál pertenece una ficha.
     */
    public static function traslapados(Carbon|string $inicio, Carbon|string $fin): Builder
    {
        return self::query()
            ->whereDate('fecha_inicio', '<=', Carbon::parse($fin))
            ->whereDate('fecha_fin', '>=', Carbon::parse($inicio));
    }

    // --- Ventana ------------------------------------------------------------------------

    public function ventanaVigente(): bool
    {
        return today()->betweenIncluded($this->ventana_inicio, $this->ventana_fin);
    }

    public function ventanaVencida(): bool
    {
        return today()->gt($this->ventana_fin);
    }

    /**
     * Si hoy se pueden generar fichas (§11 paso 2, §17.4). Exige periodo abierto *y* ventana
     * vigente: un cierre que nadie ejecutó no abre la puerta a fichas fuera de tiempo.
     */
    public function admiteFichas(): bool
    {
        return $this->estado === self::ABIERTO && $this->ventanaVigente();
    }

    // --- Estados (§8) --------------------------------------------------------------------

    public function estaEnBorrador(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    /**
     * Por qué no se puede abrir el periodo, o null si sí se puede.
     */
    public function motivoParaNoAbrir(): ?string
    {
        if (! $this->estaEnBorrador()) {
            return 'Solo se puede abrir un periodo en borrador.';
        }

        if (! $this->dias()->where('disponible', true)->exists()) {
            return 'El periodo no tiene ningún día disponible: no habría nada que seleccionar.';
        }

        return null;
    }

    /**
     * Pone el periodo a disposición del kiosco y de la caja (§8.2).
     */
    public function abrir(): void
    {
        $this->cambiarEstado(self::ABIERTO, $this->motivoParaNoAbrir());
    }

    /**
     * Cierra la generación y el pago (§8.3). Es una acción de una persona: después de cerrar se
     * genera el reporte de porciones. Vencer las fichas pendientes llegará con el módulo de fichas.
     */
    public function cerrar(): void
    {
        DB::transaction(function () {
            $this->cambiarEstado(
                self::PAGO_CERRADO,
                $this->estado === self::ABIERTO ? null : 'Solo se puede cerrar un periodo abierto.',
            );

            // Quien generó su ficha y no fue a pagar se queda sin ella (§8.3, §17.4). No reviven al
            // reabrir: contarían porciones que la cocina ya no va a preparar.
            Ficha::vencerPendientesDe($this);
        });
    }

    /**
     * Reabre un periodo cerrado (§9). Las fichas que ya vencieron no reviven: regalarían comida de
     * una semana cerrada y descuadrarían la proyección que la cocina ya recibió.
     */
    public function reabrir(): void
    {
        $this->cambiarEstado(
            self::ABIERTO,
            $this->estado === self::PAGO_CERRADO ? null : 'Solo se puede reabrir un periodo con el pago cerrado.',
        );
    }

    private function cambiarEstado(string $nuevo, ?string $motivo): void
    {
        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        $this->forceFill(['estado' => $nuevo])->save();
    }
}
