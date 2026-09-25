<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ficha de solicitud de días de comida (§11, §15).
 *
 * La genera el colaborador en el kiosco eligiendo qué días va a comer, y nace PENDIENTE: por sí
 * sola no da derecho a nada (§2.2). Solo el pago físico confirmado la vuelve PAGADA y crea los
 * derechos de consumo. Si el periodo cierra sin que haya pagado, vence (§8.3, §17.4).
 *
 * Una persona no puede tener dos fichas válidas —PENDIENTE o PAGADA— en el mismo periodo (§17.1):
 * eso evita que alguien genere varios tickets de la misma semana. Una vencida no estorba, porque
 * ya no vale para nada.
 *
 * Cada día seleccionado guarda su propio precio (`ficha_dias.precio_snapshot`), copiado del día del
 * periodo: el total de la ficha es la suma de esos precios y no cambia aunque el catálogo suba
 * después (§6.5).
 */
#[Fillable(['folio', 'persona_id', 'periodo_id', 'estado', 'total', 'generada_en'])]
class Ficha extends Model
{
    public const PENDIENTE = 'PENDIENTE';

    public const PAGADA = 'PAGADA';

    public const VENCIDA = 'VENCIDA';

    /** Estados en los que la ficha sigue contando para la regla de una por persona y periodo. */
    public const VALIDOS = [self::PENDIENTE, self::PAGADA];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => self::PENDIENTE,
        'total' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'generada_en' => 'datetime',
        ];
    }

    /**
     * El folio manda: es lo que el cobrador escanea o teclea, y con lo que la persona llega a la
     * caja (§12). Nadie conoce el id.
     */
    public function getRouteKeyName(): string
    {
        return 'folio';
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function dias(): HasMany
    {
        return $this->hasMany(FichaDia::class);
    }

    /** Fichas que todavía cuentan: pendientes de pago o ya pagadas (§17.1). */
    public function scopeValida(Builder $query): Builder
    {
        return $query->whereIn('estado', self::VALIDOS);
    }

    public function estaPendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    /**
     * Genera la ficha de una persona en un periodo, con los días que eligió.
     *
     * Todo ocurre en una transacción con bloqueo: dos kioscos a la vez no pueden crear dos fichas
     * para la misma persona y periodo.
     *
     * @param  array<int, int>  $diasElegidos  ids de dias_periodo
     *
     * @throws RuntimeException con el motivo, en el lenguaje de quien está frente al kiosco.
     */
    public static function generar(Persona $persona, Periodo $periodo, array $diasElegidos): self
    {
        return DB::transaction(function () use ($persona, $periodo, $diasElegidos) {
            $existente = self::query()
                ->where('persona_id', $persona->getKey())
                ->where('periodo_id', $periodo->getKey())
                ->valida()
                ->lockForUpdate()
                ->first();

            if ($existente) {
                throw new RuntimeException($existente->estaPendiente()
                    ? "Ya tienes una ficha de esta semana pendiente de pago (folio {$existente->folio}). Pasa a caja con ella; ahí pueden agregarte o quitarte días."
                    : "Ya pagaste tu ficha de esta semana (folio {$existente->folio}).");
            }

            $dias = DiaPeriodo::query()
                ->where('periodo_id', $periodo->getKey())
                ->whereIn('id', array_unique($diasElegidos))
                ->where('disponible', true)
                ->get();

            if ($dias->count() !== count(array_unique($diasElegidos))) {
                throw new RuntimeException('Alguno de los días que elegiste ya no está disponible. Vuelve a intentarlo.');
            }

            $ficha = self::create([
                'folio' => self::folioPara($periodo),
                'persona_id' => $persona->getKey(),
                'periodo_id' => $periodo->getKey(),
                'estado' => self::PENDIENTE,
                'total' => $dias->sum(fn (DiaPeriodo $dia) => (float) $dia->precio_aplicado),
                'generada_en' => now(),
            ]);

            foreach ($dias as $dia) {
                $ficha->dias()->create([
                    'dia_periodo_id' => $dia->getKey(),
                    // El precio se congela al elegir el día: el total de la ficha es la suma de
                    // estos, y no cambia aunque el catálogo suba después (§6.5).
                    'precio_snapshot' => $dia->precio_aplicado,
                ]);
            }

            return $ficha;
        });
    }

    public function pago(): HasOne
    {
        return $this->hasOne(Pago::class);
    }

    public function derechos(): HasMany
    {
        return $this->hasMany(DerechoConsumo::class);
    }

    /**
     * Cambia los días de la ficha antes de pagar (§13, §17.3).
     *
     * Lo hace el cobrador cuando la persona, ya en la caja, pide agregar o quitar un día. El total
     * se recalcula con el precio de cada día, y la ficha queda como si se hubiera generado así.
     *
     * @param  array<int, int>  $diasElegidos  ids de dias_periodo
     *
     * @throws RuntimeException
     */
    public function cambiarDias(array $diasElegidos): void
    {
        if (! $this->estaPendiente()) {
            throw new RuntimeException($this->estado === self::PAGADA
                ? 'Esta ficha ya está pagada: la operación quedó cerrada.'
                : 'Esta ficha venció y ya no se puede cobrar.');
        }

        $elegidos = array_values(array_unique($diasElegidos));

        DB::transaction(function () use ($elegidos) {
            $dias = DiaPeriodo::query()
                ->where('periodo_id', $this->periodo_id)
                ->whereIn('id', $elegidos)
                ->where('disponible', true)
                ->get();

            if ($dias->count() !== count($elegidos)) {
                throw new RuntimeException('Alguno de los días elegidos no es de esta semana o no tiene servicio.');
            }

            $this->dias()->whereNotIn('dia_periodo_id', $elegidos)->delete();

            foreach ($dias as $dia) {
                $this->dias()->updateOrCreate(
                    ['dia_periodo_id' => $dia->getKey()],
                    // Al agregar un día se congela su precio, igual que al generar la ficha (§6.5).
                    ['precio_snapshot' => $dia->precio_aplicado],
                );
            }

            $this->forceFill(['total' => $this->dias()->sum('precio_snapshot')])->save();
        });

        $this->load('dias');
    }

    /**
     * Confirma el cobro físico (§12) y crea los derechos de consumo, uno por día pagado (§2.2,
     * §2.3).
     *
     * El sistema no cobra: valida el monto como un POS. Menos de lo que cuesta se rechaza, lo justo
     * o de más se acepta y se calcula el cambio (§12.1).
     *
     * @throws RuntimeException
     */
    public function confirmarPago(float $montoRecibido, User $cobrador): Pago
    {
        return DB::transaction(function () use ($montoRecibido, $cobrador) {
            // Se relee bajo bloqueo: dos cajas no pueden cobrar la misma ficha a la vez.
            $ficha = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if (! $ficha->estaPendiente()) {
                throw new RuntimeException($ficha->estado === self::PAGADA
                    ? 'Esta ficha ya estaba pagada.'
                    : 'Esta ficha venció y ya no se puede cobrar.');
            }

            $total = (float) $ficha->total;

            if ($ficha->dias()->count() === 0) {
                throw new RuntimeException('La ficha se quedó sin días: agrega al menos uno antes de cobrar.');
            }

            if ($montoRecibido < $total) {
                throw new RuntimeException(sprintf(
                    'El monto recibido ($%s) no alcanza para pagar $%s. Pide la diferencia antes de confirmar.',
                    number_format($montoRecibido, 2),
                    number_format($total, 2),
                ));
            }

            $pago = $ficha->pago()->create([
                'total_cobrado' => $total,
                'monto_recibido' => $montoRecibido,
                'cambio' => round($montoRecibido - $total, 2),
                'cobrador_id' => $cobrador->getKey(),
                'confirmado_en' => now(),
            ]);

            $ficha->forceFill(['estado' => self::PAGADA])->save();

            foreach ($ficha->dias()->with('diaPeriodo')->get() as $dia) {
                $ficha->derechos()->create([
                    'persona_id' => $ficha->persona_id,
                    'periodo_id' => $ficha->periodo_id,
                    'dia_periodo_id' => $dia->dia_periodo_id,
                    'estado' => DerechoConsumo::VIGENTE,
                    'precio_pagado' => $dia->precio_snapshot,
                ]);
            }

            $this->setRawAttributes($ficha->getAttributes(), true);

            return $pago;
        });
    }

    /**
     * Folio legible y único: año, semana del servicio y consecutivo dentro del periodo. Por
     * ejemplo, `2026-40-0007`. Solo dígitos y guiones, para que el cobrador lo teclee sin dudar si
     * una letra es O o cero, y con la semana a la vista para reconocerlo de un vistazo.
     */
    public static function folioPara(Periodo $periodo): string
    {
        $consecutivo = self::query()->where('periodo_id', $periodo->getKey())->lockForUpdate()->count() + 1;

        do {
            $folio = sprintf(
                '%s-%s-%04d',
                $periodo->fecha_inicio->isoFormat('GGGG'),
                $periodo->fecha_inicio->isoFormat('WW'),
                $consecutivo,
            );
            $consecutivo++;
            // Un periodo distinto de la misma semana (o una ficha borrada a mano) podría chocar.
        } while (self::query()->where('folio', $folio)->exists());

        return $folio;
    }

    /**
     * Vence las fichas pendientes de un periodo al cerrarlo (§8.3, §17.4). Las pagadas no se tocan,
     * y una vencida no revive aunque el periodo se reabra.
     */
    public static function vencerPendientesDe(Periodo $periodo): int
    {
        return self::query()
            ->where('periodo_id', $periodo->getKey())
            ->where('estado', self::PENDIENTE)
            ->update(['estado' => self::VENCIDA]);
    }
}
