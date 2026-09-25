<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confirmación del cobro físico de una ficha (§12, §14).
 *
 * El dinero se recibe fuera del sistema: aquí solo se registra que el cobrador lo contó y lo dio
 * por bueno (§2.1). Por eso guarda las tres cifras de la operación —lo que se cobró, lo que
 * entregó la persona y el cambio— y quién la confirmó: es lo que se mira al cuadrar la caja y lo
 * que respalda un reclamo.
 *
 * Un pago por ficha, y confirmarlo es lo que crea los derechos de consumo (§2.2).
 */
#[Fillable(['ficha_id', 'total_cobrado', 'monto_recibido', 'cambio', 'cobrador_id', 'confirmado_en'])]
class Pago extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_cobrado' => 'decimal:2',
            'monto_recibido' => 'decimal:2',
            'cambio' => 'decimal:2',
            'confirmado_en' => 'datetime',
        ];
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    public function cobrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cobrador_id');
    }
}
