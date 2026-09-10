<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma pública de una cuenta de sistema (§3.1, §5).
 *
 * `password` y `remember_token` nunca aparecen aquí; además el modelo los declara en
 * #[Hidden], de modo que ni un `toArray()` accidental los expondría.
 *
 * @mixin User
 */
class UsuarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'activo' => $this->activo,
            // La UI lo usa para bloquear la suspensión y el cambio de rol; el backend rechaza
            // igualmente (§5.6).
            'protegido' => $this->protegido,
            'persona_id' => $this->persona_id,
            'rol_id' => $this->rol_id,
            'persona' => $this->whenLoaded('persona', fn () => [
                'id' => $this->persona->id,
                'numero_empleado' => $this->persona->numero_empleado,
                'nombre_completo' => $this->persona->nombre_completo,
                'estado' => $this->persona->estado,
            ]),
            'rol' => $this->whenLoaded('rol', fn () => [
                'id' => $this->rol->id,
                'nombre' => $this->rol->nombre,
                'activo' => $this->rol->activo,
            ]),
            // La UI necesita distinguir "cuenta suspendida" de "cuenta activa que aun asi no
            // puede entrar" porque la persona esta de baja o su rol fue desactivado (§3.3).
            'puede_operar' => $this->when(
                $this->relationLoaded('persona') && $this->relationLoaded('rol'),
                fn () => $this->puedeOperar(),
            ),
            // Solo cuando el query cargó `rol.permisos` (lo hace /api/me). La UI los usa para
            // ocultar botones; la comprobación de verdad ocurre en el backend (§5.6).
            'permisos' => $this->when(
                $this->relationLoaded('rol') && $this->rol?->relationLoaded('permisos'),
                fn () => $this->rol->permisos->pluck('clave')->all(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
