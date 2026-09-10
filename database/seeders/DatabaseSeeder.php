<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * El orden importa: RolSeeder asigna permisos por clave, así que el catálogo de §5.3 debe
     * existir antes. Ambos son idempotentes y pueden volver a correrse tras agregar un módulo.
     *
     * AdministradorSeeder va al final porque necesita el rol Administrador ya creado. Crea la
     * única cuenta que un despliegue nuevo necesita para arrancar: sin ella, las rutas exigen
     * permisos que nadie tendría (§5.6). No lleva contraseña por defecto en el repositorio.
     */
    public function run(): void
    {
        $this->call([
            PermisoSeeder::class,
            RolSeeder::class,
            AdministradorSeeder::class,
        ]);
    }
}
