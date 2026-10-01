<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La zona horaria de la aplicación es una regla de negocio, no un detalle de configuración.
 *
 * Casi todas las fechas del sistema son fechas de negocio —el día de servicio, el derecho de hoy,
 * la ventana para pedir y pagar, el corte de caja— y todas tienen que cambiar a la medianoche de
 * aquí. Con la aplicación en UTC el día cambiaba a las seis de la tarde, así que a las 18:30 el
 * checador ya buscaba los derechos del día siguiente y rechazaba a quien sí había pagado.
 */
class ZonaHorariaTest extends TestCase
{
    public function test_la_aplicacion_corre_en_la_hora_del_comedor(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        $this->assertSame('America/Mexico_City', date_default_timezone_get());
    }

    public function test_el_dia_de_negocio_cambia_a_la_medianoche_local_no_a_las_seis_de_la_tarde(): void
    {
        // Un instante real: las 4:30 UTC del 1 de octubre son las 22:30 del 30 de septiembre aquí.
        $this->travelTo(Carbon::parse('2026-10-01 04:30', 'UTC'));

        $this->assertSame('2026-09-30', today()->toDateString());
        $this->assertSame('22:30', now()->format('H:i'));
    }

    public function test_las_horas_viajan_al_navegador_como_el_instante_que_fueron(): void
    {
        // La API serializa en UTC con 'Z', y el navegador lo vuelve a pintar en hora local: por eso
        // `new Date(valor)` es correcto en las pantallas y no hay que corregirlo a mano.
        $this->travelTo(Carbon::parse('2026-10-01 13:30'));

        $this->assertStringStartsWith('2026-10-01T19:30:00', now()->toJSON());
        $this->assertStringEndsWith('Z', now()->toJSON());
    }
}
