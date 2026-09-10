<?php

namespace App\Models;

use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['numero_empleado', 'nombre', 'primer_apellido', 'segundo_apellido', 'departamento_id', 'foto_path', 'estado'])]
class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [

        'estado' => 'ACTIVO',

    ];

    /**
     * Departamento al que está adscrita la persona (§3.4).
     *
     * La llave foránea `departamento_id` vive en esta tabla, por eso es belongsTo y no hasOne.
     * El inverso es Departamento::personas() (hasMany).
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * Cuenta de sistema OPCIONAL de la persona (§3.1). `null` es un caso normal, no un error:
     * quien no tiene cuenta sigue usando su gafete para el comedor, solo no entra a los
     * módulos administrativos. La unicidad de `users.persona_id` limita esto a una sola cuenta.
     */
    public function cuenta(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Nombre para mostrar en tablas, gafetes y reportes. `segundo_apellido` es opcional, por eso
     * se filtran los vacíos antes de unir.
     *
     * @return Attribute<string, never>
     */
    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->nombre,
            $this->primer_apellido,
            $this->segundo_apellido,
        ])));
    }
}
