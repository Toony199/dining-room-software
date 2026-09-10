<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rol>
 */
class RolFactory extends Factory
{
    protected $model = Rol::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => ucfirst(fake()->unique()->words(2, true)),
            'descripcion' => fake()->sentence(),
            'activo' => true,
        ];
    }

    /**
     * Rol desactivado (§5.5). Sus usuarios dejan de poder operar.
     */
    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}
