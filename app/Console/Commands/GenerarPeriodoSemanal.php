<?php

namespace App\Console\Commands;

use App\Models\Periodo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Genera el periodo de la semana siguiente en BORRADOR (§6.1).
 *
 * Pensado para correr los martes, cuando aún queda la semana entera para que el gestor marque
 * festivos, abra el periodo y la gente pida y pague. Es idempotente: si el periodo ya existe no
 * crea otro, así que ejecutarlo dos veces no hace daño.
 *
 * Queda programado en routes/console.php, pero solo se dispara si el servidor tiene la tarea que
 * ejecuta `schedule:run`. Mientras no la tenga, el mismo trabajo está a un botón en la pantalla de
 * periodos.
 */
class GenerarPeriodoSemanal extends Command
{
    protected $signature = 'comedor:generar-periodo
                            {--semana= : Lunes de la semana a generar (AAAA-MM-DD). Por omisión, la semana siguiente.}';

    protected $description = 'Crea en borrador el periodo de servicio de la semana siguiente';

    public function handle(): int
    {
        $lunes = $this->option('semana')
            ? Carbon::parse($this->option('semana'))->startOfDay()
            : Periodo::lunesDeLaSemanaSiguiente();

        if ($existente = Periodo::query()->whereDate('fecha_inicio', $lunes)->first()) {
            $this->line("El periodo de la semana del {$lunes->format('d/m/Y')} ya existe (#{$existente->id}). No se creó otro.");

            return self::SUCCESS;
        }

        try {
            $periodo = Periodo::generarSemana($lunes, automatico: true);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Periodo #%d creado en borrador: servicio del %s al %s, ventana del %s al %s.',
            $periodo->id,
            $periodo->fecha_inicio->format('d/m/Y'),
            $periodo->fecha_fin->format('d/m/Y'),
            $periodo->ventana_inicio->format('d/m/Y'),
            $periodo->ventana_fin->format('d/m/Y'),
        ));

        return self::SUCCESS;
    }
}
