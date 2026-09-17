<?php

namespace Database\Factories;

use App\Models\Gafete;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Gafete>
 */
class GafeteFactory extends Factory
{
    protected $model = Gafete::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'qr_token' => (string) Str::ulid(),
            'estado' => 'ACTIVO',
            'emitido_en' => now(),
        ];
    }

    /**
     * Gafete reemplazado por una reposición (§4.3).
     */
    public function inactivo(): static
    {
        return $this->state(fn () => ['estado' => 'INACTIVO']);
    }
}
