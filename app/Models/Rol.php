<?php

namespace App\Models;

use Database\Factories\RolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nombre', 'descripcion', 'activo'])]
class Rol extends Model
{
    /** @use HasFactory<RolFactory> */
    use HasFactory;

    /**
     * La tabla no sigue la convención de Eloquent: el plural de `Rol` sería `rols`.
     *
     * @var string
     */
    protected $table = 'roles';

    /**
     * Valores por defecto (para que un alta sin `activo` quede ACTIVO en la respuesta sin
     * depender del default de la BD).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'protegido' => 'boolean',
        ];
    }

    /**
     * Un rol protegido lo necesita el sistema para funcionar: no se edita ni se desactiva
     * desde la aplicación (§5.2). Hoy es el rol de acceso total, y quitarle permisos o darlo
     * de baja dejaría la instalación sin quien pueda administrarla.
     *
     * `protegido` no está en el #[Fillable]: la bandera la pone el seeder, no un payload.
     */
    public function esProtegido(): bool
    {
        return (bool) $this->protegido;
    }

    /**
     * Permisos que otorga el rol (§5.4). Al cambiar este pivote, todos los usuarios del rol
     * adquieren o pierden el permiso de inmediato: los permisos no se copian a la cuenta.
     */
    public function permisos(): BelongsToMany
    {
        return $this->belongsToMany(Permiso::class, 'permiso_rol', 'rol_id', 'permiso_id')
            ->withTimestamps();
    }

    /**
     * Cuentas de sistema que tienen este rol (§5.1, un solo rol por usuario).
     *
     * Se usa para la regla §5.5: un rol con usuarios asignados no puede desactivarse.
     */
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }
}
