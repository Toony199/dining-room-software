<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Registra un precio nuevo y cierra el anterior el día previo, para que la línea de tiempo no
     * tenga huecos ni dos precios el mismo día.
     */
    public static function registrar(float $precio, Carbon|string $desde, ?int $usuarioId = null): self
    {
        $desde = Carbon::parse($desde)->startOfDay();

        return DB::transaction(function () use ($precio, $desde, $usuarioId) {
            // Bloquea la última para que dos altas a la vez no dejen dos tarifas abiertas.
            $anterior = self::query()->orderByDesc('vigente_desde')->orderByDesc('id')->lockForUpdate()->first();

            $anterior?->forceFill(['vigente_hasta' => $desde->copy()->subDay()])->save();

            return self::create([
                'precio' => $precio,
                'vigente_desde' => $desde,
                'vigente_hasta' => null,
                'creado_por' => $usuarioId,
            ]);
        });
    }

    /** Todavía no entra en vigor: se registró para una fecha futura. */
    public function estaProgramada(): bool
    {
        return $this->vigente_desde->isFuture();
    }

    public function estaVigente(): bool
    {
        return ! $this->estaProgramada()
            && ($this->vigente_hasta === null || ! $this->vigente_hasta->isPast());
    }
}
