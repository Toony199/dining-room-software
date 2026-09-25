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
#[Fillable(['persona_id', 'periodo_id', 'dia_periodo_id', 'ficha_id', 'estado', 'precio_pagado'])]
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
}
