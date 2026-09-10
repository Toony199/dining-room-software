<?php

namespace Database\Factories;

use App\Models\Permiso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permiso>
 */
class PermisoFactory extends Factory
{
    protected $model = Permiso::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $modulo = fake()->unique()->word();

        return [
            'clave' => $modulo.'.'.fake()->randomElement(['ver', 'crear', 'editar', 'desactivar']),
            'descripcion' => fake()->sentence(),
            'modulo' => $modulo,
        ];
    }
}
