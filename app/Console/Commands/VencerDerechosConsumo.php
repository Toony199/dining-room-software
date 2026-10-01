<?php

namespace App\Console\Commands;

use App\Models\DerechoConsumo;
use Illuminate\Console\Command;

/**
 * Vence los derechos de días que ya pasaron y nadie usó (§16.5, §2.3).
 *
 * Un derecho no utilizado no se transfiere ni se repone: el día pasó. Marcarlos es lo que permite
 * distinguir después "no fue a comer" de "todavía puede ir", que es lo que miran los reportes.
 *
 * Queda programado de madrugada, y solo corre si el servidor tiene la tarea que ejecuta
 * `schedule:run`. No pasa nada si se ejecuta tarde o dos veces: solo toca los días ya pasados.
 */
class VencerDerechosConsumo extends Command
{
    protected $signature = 'comedor:vencer-derechos';

    protected $description = 'Marca como vencidos los derechos de consumo de días que ya pasaron';

    public function handle(): int
    {
        $vencidos = DerechoConsumo::vencerLosDeDiasPasados();

        $this->info($vencidos === 0
            ? 'No había derechos por vencer.'
            : "Se vencieron {$vencidos} derechos de días anteriores.");

        return self::SUCCESS;
    }
}
