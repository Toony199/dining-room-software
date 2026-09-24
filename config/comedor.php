<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cuenta administradora de arranque
    |--------------------------------------------------------------------------
    |
    | Datos con los que AdministradorSeeder crea la primera cuenta de un despliegue nuevo
    | (§3.1). Sin ella nadie podría entrar a crear las demás, porque las rutas ya exigen
    | sesión y permisos (§5.6).
    |
    | `password` en blanco hace que el seeder genere una y la imprima una sola vez, lo cual
    | es preferible a dejar una por defecto en el repositorio.
    |
    */

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'dev@arod.com.mx'),
        'password' => env('ADMIN_PASSWORD'),
        'numero_empleado' => env('ADMIN_NUMERO_EMPLEADO', 'ADMIN-0001'),
        'nombre' => env('ADMIN_NOMBRE', 'Administrador'),
        'apellido' => env('ADMIN_APELLIDO', 'del Sistema'),
        'departamento' => env('ADMIN_DEPARTAMENTO', 'Sistemas'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Periodos de servicio
    |--------------------------------------------------------------------------
    |
    | Con qué forma nace un periodo generado automáticamente (§6.1). El periodo cubre la
    | semana siguiente, de lunes a viernes, y su ventana para generar fichas y pagar corre
    | de miércoles a viernes de la semana anterior: así, cuando llega el lunes del servicio,
    | ya se sabe cuántas porciones hay que preparar cada día.
    |
    | Los desplazamientos de la ventana se cuentan en días desde el lunes del periodo, en
    | negativo porque caen antes: -5 es el miércoles previo y -3 el viernes previo. La spec
    | (§7.2) pedía la ventana dentro del periodo; se relajó a "antes del periodo o dentro de
    | él" por esta decisión (ver docs/analisis-diseno.md §10).
    |
    */

    'periodos' => [
        'dias_de_servicio' => (int) env('COMEDOR_DIAS_DE_SERVICIO', 5),
        'ventana_inicio_offset' => (int) env('COMEDOR_VENTANA_INICIO_OFFSET', -5),
        'ventana_fin_offset' => (int) env('COMEDOR_VENTANA_FIN_OFFSET', -3),
    ],

];
