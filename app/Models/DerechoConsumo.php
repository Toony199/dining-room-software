<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derecho a comer un día concreto (§2.3, §15).
 *
 * Nace solo al confirmarse el pago (§2.2), uno por cada día pagado, y es independiente de los
 * demás: usar el lunes no afecta al martes. No se transfiere a otra persona ni se recorre a otro
 * día, y el que no se usa vence (§2.3, §16.4).
 *
 * `precio_pagado` es lo que costó ese día, copiado de la ficha: el histórico no cambia aunque la
 * tarifa suba después (§6.5).
 */
#[Fillable([
    'persona_id',
    'periodo_id',
    'dia_periodo_id',
    'ficha_id',
    'estado',
    'precio_pagado',
    'utilizado_en',
    'validado_por',
])]
class DerechoConsumo extends Model
{
    public const VIGENTE = 'VIGENTE';

    public const UTILIZADO = 'UTILIZADO';

    public const VENCIDO = 'VENCIDO';

    protected $table = 'derechos_consumo';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => self::VIGENTE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_pagado' => 'decimal:2',
            'utilizado_en' => 'datetime',
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

    public function diaPeriodo(): BelongsTo
    {
        return $this->belongsTo(DiaPeriodo::class, 'dia_periodo_id');
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por');
    }

    /**
     * El derecho que esta persona tiene para hoy, si compró este día. Null si no lo compró: no
     * existe el derecho, que es distinto de tenerlo vencido o usado (§16.3).
     */
    public static function deHoy(Persona $persona): ?self
    {
        return self::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('diaPeriodo', fn ($dia) => $dia->whereDate('fecha', today()))
            ->with('diaPeriodo')
            ->first();
    }

    public function estaVigente(): bool
    {
        return $this->estado === self::VIGENTE;
    }

    /**
     * Marca el derecho como consumido (§16.1). Devuelve false si alguien se adelantó entre la
     * consulta y esta llamada: dos lectores en la fila no pueden gastar el mismo derecho.
     */
    public function usar(?User $validador = null): bool
    {
        // El UPDATE condicionado al estado es lo que decide: gana quien llegue primero, y el
        // segundo se entera porque no afectó ninguna fila.
        $consumido = self::query()
            ->whereKey($this->getKey())
            ->where('estado', self::VIGENTE)
            ->update([
                'estado' => self::UTILIZADO,
                'utilizado_en' => now(),
                'validado_por' => $validador?->getKey(),
                'updated_at' => now(),
            ]);

        if ($consumido) {
            $this->refresh();
        }

        return (bool) $consumido;
    }

    /**
     * Vence los derechos de días que ya pasaron y nadie usó (§16.5, §2.3). No se transfieren ni se
     * reponen: el día pasó.
     */
    public static function vencerLosDeDiasPasados(): int
    {
        return self::query()
            ->where('estado', self::VIGENTE)
            ->whereHas('diaPeriodo', fn ($dia) => $dia->whereDate('fecha', '<', today()))
            ->update(['estado' => self::VENCIDO]);
    }
}
