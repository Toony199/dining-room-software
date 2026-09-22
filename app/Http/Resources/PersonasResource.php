<?php

namespace App\Http\Resources;

use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de una persona (§3).
 *
 * El contrato de la API se declara aquí y no en la migración: agregar una columna a la tabla
 * no la publica automáticamente, y renombrarla no rompe al frontend sin aviso.
 *
 * @mixin Persona
 */
class PersonasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_empleado' => $this->numero_empleado,
            'nombre' => $this->nombre,
            'primer_apellido' => $this->primer_apellido,
            'segundo_apellido' => $this->segundo_apellido,
            // Se arma en el backend para que la tabla y los reportes no repitan la
            // concatenación en cada cliente.
            'nombre_completo' => $this->nombre_completo,
            // El que se imprime en el gafete (ver Persona::nombreGafete).
            'nombre_gafete' => $this->nombre_gafete,
            'departamento_id' => $this->departamento_id,
            // `whenLoaded` solo incluye la clave si el query hizo with('departamento'): así
            // ningún endpoint revienta por leer una relación que no cargó.
            'departamento' => $this->whenLoaded('departamento', fn () => [
                'id' => $this->departamento->id,
                'nombre' => $this->departamento->nombre,
                'activo' => $this->departamento->activo,
            ]),
            // §3.1: la cuenta es opcional, y `null` aquí es un caso normal, no un error: esa
            // persona usa el comedor con su gafete pero no entra a los módulos administrativos.
            'cuenta' => $this->whenLoaded('cuenta', fn () => $this->cuenta === null ? null : [
                'id' => $this->cuenta->id,
                'email' => $this->cuenta->email,
                'activo' => $this->cuenta->activo,
                // La persona de la cuenta administradora no puede darse de baja.
                'protegido' => $this->cuenta->protegido,
                'rol' => $this->cuenta->relationLoaded('rol') && $this->cuenta->rol !== null ? [
                    'id' => $this->cuenta->rol->id,
                    'nombre' => $this->cuenta->rol->nombre,
                ] : null,
            ]),
            // Resumen del gafete activo, sin el token del QR: ese solo sale por la impresión.
            'gafete' => $this->whenLoaded('gafeteActivo', fn () => $this->gafeteActivo === null ? null : [
                'id' => $this->gafeteActivo->id,
                'emitido_en' => $this->gafeteActivo->emitido_en,
            ]),
            // La ruta del archivo no sale del servidor: solo la URL del API que entrega la foto
            // a quien tiene permiso (§3.1).
            'foto_url' => $this->urlDeFoto(),
            'estado' => $this->estado,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
