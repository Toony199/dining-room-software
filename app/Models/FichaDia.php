<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Día elegido dentro de una ficha (§11 paso 3, §13).
 *
 * `precio_snapshot` es el precio de ese día copiado al momento de elegirlo. El total de la ficha es
 * la suma de estos, así que lo que se cobró queda fijo aunque el catálogo cambie después (§6.5).
 */
#[Fillable(['ficha_id', 'dia_periodo_id', 'precio_snapshot'])]
class FichaDia extends Model
{
    protected $table = 'ficha_dias';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_snapshot' => 'decimal:2',
        ];
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    public function diaPeriodo(): BelongsTo
    {
        return $this->belongsTo(DiaPeriodo::class, 'dia_periodo_id');
    }
}
