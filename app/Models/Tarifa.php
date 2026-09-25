<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Precio del día de comedor (§6.4, §18).
 *
 * Es general para todos: no hay precios por departamento, rol ni tipo de empleado. Lo que cambia es
 * la fecha desde la que rige, así que el catálogo es una línea de tiempo sin huecos: cada tarifa
 * vale desde su `vigente_desde` hasta el día anterior a la siguiente, y la última queda abierta.
 *
 * Las tarifas no se editan ni se borran: son el respaldo de lo que ya se cobró. Para cambiar el
 * precio se registra una nueva, que puede entrar hoy o quedar programada para más adelante.
 *
 * Este precio se COPIA a cada día del periodo cuando se crea (`dias_periodo.precio_aplicado`), y
 * desde ese momento el periodo ya no depende de este catálogo: si el precio sube, lo ya armado
 * conserva el suyo (§6.5).
 */
#[Fillable(['precio', 'vigente_desde', 'vigente_hasta', 'creado_por'])]
class Tarifa extends Model
{
    /** Id de la tarifa que rige hoy; null cuando todavía no se ha averiguado (ver idVigente). */
    private static ?int $idVigente = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
        ];
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * La tarifa que rige en una fecha: la última que empezó en o antes de esa fecha y que no había
     * terminado. Null si ese día es anterior a la primera tarifa registrada.
     */
    public static function vigenteEn(Carbon|string $fecha): ?self
    {
        $dia = Carbon::parse($fecha)->toDateString();

        return self::query()
            ->whereDate('vigente_desde', '<=', $dia)
            ->where(fn (Builder $query) => $query
                ->whereNull('vigente_hasta')
                ->orWhereDate('vigente_hasta', '>=', $dia))
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    /** La tarifa que rige hoy. */
    public static function vigente(): ?self
    {
        return self::vigenteEn(today());
    }

    /** La última registrada, que puede ser una programada para más adelante. */
    public static function ultima(): ?self
    {
        return self::query()->orderByDesc('vigente_desde')->orderByDesc('id')->first();
    }

    /**
     * Registra un precio a partir de una fecha y deja la línea de tiempo coherente.
     *
     * El catálogo es una línea de tiempo, no una lista a la que solo se agrega al final: un precio
     * puede entrar hoy aunque ya haya uno programado para más adelante. Se acomodan los vecinos:
     *
     *  - el anterior termina el día previo al nuevo;
     *  - si otro empieza el mismo día, queda reemplazado: se le deja ese día, porque de verdad
     *    rigió hasta el cambio, y a partir de ahí manda el nuevo (vigenteEn() desempata por el
     *    último registrado);
     *  - si ya hay uno más adelante, el nuevo termina el día antes de que entre aquel, para no
     *    pisarlo.
     */
    public static function registrar(float $precio, Carbon|string $desde, ?int $usuarioId = null): self
    {
        $desde = Carbon::parse($desde)->startOfDay();

        return DB::transaction(function () use ($precio, $desde, $usuarioId) {
            // Tabla chica y muy poco escrita: se bloquea entera para que dos altas a la vez no
            // dejen la línea de tiempo a medio acomodar.
            self::query()->lockForUpdate()->get(['id']);

            $anterior = self::query()
                ->whereDate('vigente_desde', '<', $desde)
                ->orderByDesc('vigente_desde')
                ->orderByDesc('id')
                ->first();

            $anterior?->forceFill(['vigente_hasta' => $desde->copy()->subDay()])->save();

            foreach (self::query()->whereDate('vigente_desde', $desde)->get() as $reemplazada) {
                $reemplazada->forceFill(['vigente_hasta' => $desde->copy()])->save();
            }

            $siguiente = self::query()
                ->whereDate('vigente_desde', '>', $desde)
                ->orderBy('vigente_desde')
                ->first();

            return self::create([
                'precio' => $precio,
                'vigente_desde' => $desde,
                'vigente_hasta' => $siguiente?->vigente_desde->copy()->subDay(),
                'creado_por' => $usuarioId,
            ]);
        });
    }

    /** Todavía no entra en vigor: se registró para una fecha futura. */
    public function estaProgramada(): bool
    {
        return $this->vigente_desde->isFuture();
    }

    /**
     * Cancela un precio que todavía no entra en vigor y vuelve a unir la línea de tiempo: el
     * anterior se extiende hasta donde llegaba este.
     *
     * Se borra en vez de marcarse, porque un precio que nunca rigió no es historia de nada: dejarlo
     * solo ensucia el catálogo. Los precios que ya rigieron no se tocan jamás (§6.5), y esta es la
     * única excepción a que aquí no se borre nada.
     *
     * @throws RuntimeException si ya está en vigor.
     */
    public function cancelar(): void
    {
        if (! $this->estaProgramada()) {
            throw new RuntimeException('Ese precio ya está en vigor: solo se cancelan los programados para más adelante.');
        }

        DB::transaction(function () {
            self::query()->lockForUpdate()->get(['id']);

            $anterior = self::query()
                ->whereKeyNot($this->getKey())
                ->whereDate('vigente_desde', '<=', $this->vigente_desde)
                ->orderByDesc('vigente_desde')
                ->orderByDesc('id')
                ->first();

            $siguiente = self::query()
                ->whereKeyNot($this->getKey())
                ->whereDate('vigente_desde', '>', $this->vigente_desde)
                ->orderBy('vigente_desde')
                ->first();

            $this->delete();

            // El anterior recupera el tramo que ocupaba este, hasta el siguiente o sin fin.
            $anterior?->forceFill([
                'vigente_hasta' => $siguiente?->vigente_desde->copy()->subDay(),
            ])->save();
        });
    }

    /**
     * La que rige hoy. Se compara contra vigenteEn(), y no contra las fechas de esta fila, porque
     * un precio reemplazado el mismo día comparte fecha con el que lo sustituyó: el empate lo
     * desempata el orden de registro, no el calendario.
     */
    public function estaVigente(): bool
    {
        return $this->exists && $this->getKey() === self::idVigente();
    }

    /**
     * Id de la tarifa que rige hoy, recordado mientras nadie registre otra: el historial pregunta
     * por cada renglón y no tiene sentido repetir la consulta. Registrar un precio lo olvida, que
     * es justo cuando deja de valer.
     */
    private static function idVigente(): ?int
    {
        return self::$idVigente ??= self::vigente()?->getKey() ?? 0;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$idVigente = null);
        static::deleted(fn () => self::$idVigente = null);
    }
}
