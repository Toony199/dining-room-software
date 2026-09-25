<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
