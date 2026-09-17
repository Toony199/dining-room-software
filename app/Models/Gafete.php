<?php

namespace App\Models;

use Database\Factories\GafeteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gafete con QR de una persona (§4).
 *
 * `qr_token` es un identificador opaco (ULID), único por emisión y distinto del número de
 * empleado: es lo que codifica el QR y lo que el kiosco y el comedor usarán para identificar a
 * la persona (RN-20). Por eso va en #[Hidden]: quien tiene el token puede fabricar un gafete
 * que funcione, y solo debe salir por el endpoint de impresión.
 *
 * Se emiten con Persona::emitirGafete(), que garantiza un solo gafete ACTIVO por persona.
 */
#[Fillable(['persona_id', 'qr_token', 'estado', 'emitido_en'])]
#[Hidden(['qr_token'])]
class Gafete extends Model
{
    /** @use HasFactory<GafeteFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'ACTIVO',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emitido_en' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * Un gafete INACTIVO fue reemplazado por otro y dejó de funcionar (§4.3). No vuelve a
     * activarse: reponer siempre emite uno nuevo con otro token.
     */
    public function estaActivo(): bool
    {
        return $this->estado === 'ACTIVO';
    }
}
