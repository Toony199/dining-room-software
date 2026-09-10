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

];
