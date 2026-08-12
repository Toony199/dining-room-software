<?php

namespace App\Models;

use Database\Factories\DepartamentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'activo'])]
class Departamento extends Model
{
    /** @use HasFactory<DepartamentoFactory> */
    use HasFactory;

    /**
     * Valores por defecto de atributos (para que un alta sin `activo` quede ACTIVO en la
     * respuesta sin depender del default de la BD).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    /**
     * Casts de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Colaboradores adscritos al departamento (§3.4, relación 1:N).
     */
    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }
}
