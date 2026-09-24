<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Día individual de un periodo de servicio (§6.2).
 *
 * Cada día es un registro con sus propias propiedades, y no una columna por día de la semana: así
 * un día puede marcarse como festivo, quedar fuera del servicio con su motivo, o tener su propio
 * precio sin tocar a los demás (§6.3).
 *
 * `precio_aplicado` es una copia del catálogo de tarifas hecha al crear el periodo, no una
 * referencia viva: si el precio general cambia después, este día conserva el suyo (§6.5).
 *
 * Un día no disponible no se puede seleccionar, no se cobra, no genera derecho de consumo y no
 * cuenta para la proyección de porciones (§6.3).
 */
#[Fillable([
    'periodo_id',
    'fecha',
    'dia_semana',
    'disponible',
    'es_festivo',
    'motivo_indisponibilidad',
    'precio_aplicado',
])]
class DiaPeriodo extends Model
{
    protected $table = 'dias_periodo';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'disponible' => 'boolean',
            'es_festivo' => 'boolean',
            'precio_aplicado' => 'decimal:2',
        ];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class, 'periodo_id');
    }
}
