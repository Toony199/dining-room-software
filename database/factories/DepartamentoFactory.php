<?php

namespace Database\Factories;

use App\Models\Departamento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Departamento>
 */
class DepartamentoFactory extends Factory
{
    protected $model = Departamento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'activo' => true,
        ];
    }

    /**
     * Departamento dado de baja lógicamente (§3.4).
     */
    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}
