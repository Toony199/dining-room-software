<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Periodo de la semana siguiente, en borrador, los martes (§6.1).
 *
 * Solo corre si el servidor tiene programada la tarea que ejecuta `php artisan schedule:run` cada
 * minuto. Mientras no la tenga, el mismo trabajo está a un botón en la pantalla de periodos, y el
 * comando es idempotente, así que ejecutarlo de más no duplica nada.
 */
Schedule::command('comedor:generar-periodo')->tuesdays()->at('06:00');
