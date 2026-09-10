<?php

namespace Database\Factories;

use App\Models\Departamento;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    protected $model = Persona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Único y nunca reasignado (§3.2). Se genera como cadena porque la columna es
            // string: hay plantillas de número de empleado con ceros a la izquierda.
            'numero_empleado' => (string) fake()->unique()->numberBetween(1000, 99999),
            'nombre' => fake()->firstName(),
            'primer_apellido' => fake()->lastName(),
            'segundo_apellido' => fake()->lastName(),
            'departamento_id' => Departamento::factory(),
            'foto_path' => null,
            'estado' => 'ACTIVO',
        ];
    }

    /**
     * Persona dada de baja lógicamente (§3.3). No opera: ni gafete, ni fichas, ni consumos.
     */
    public function inactiva(): static
    {
        return $this->state(fn () => ['estado' => 'INACTIVO']);
    }

    /**
     * Persona con un solo apellido: la columna es nullable y el alta debe aceptarlo.
     */
    public function sinSegundoApellido(): static
    {
        return $this->state(fn () => ['segundo_apellido' => null]);
    }
}
