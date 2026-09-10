<?php

namespace App\Models;

use Database\Factories\PermisoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['clave', 'descripcion', 'modulo'])]
class Permiso extends Model
{
    /** @use HasFactory<PermisoFactory> */
    use HasFactory;

    /**
     * Roles que otorgan este permiso (§5.4).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'permiso_rol', 'permiso_id', 'rol_id')
            ->withTimestamps();
    }
}
