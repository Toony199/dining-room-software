<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Cuenta de sistema OPCIONAL de una persona (§3.1). Una persona sin cuenta sigue siendo
 * consumidora del comedor mediante su gafete; simplemente no entra a los módulos
 * administrativos. La unicidad de `persona_id` en la tabla impone 1 persona → máx. 1 cuenta.
 */
#[Fillable(['persona_id', 'rol_id', 'activo', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Valores por defecto de atributos.
     *
     * La columna ya tiene default en la BD, pero Eloquent no relee la fila tras un insert:
     * sin esto, la respuesta del alta diría `activo: null` hasta el siguiente fetch.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => true,
        // Ídem: sin esto una cuenta recién creada respondería `protegido: null`.
        'protegido' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'protegido' => 'boolean',
        ];
    }

    /**
     * ¿Es la cuenta administradora de arranque?
     *
     * Una cuenta protegida no puede quedar inutilizable desde la aplicación: no se da de baja
     * a su persona, no se suspende y no se le cambia el rol. Cualquiera de las tres dejaría la
     * instalación sin quien la administre. Correo y contraseña sí se pueden cambiar.
     *
     * `protegido` no está en el #[Fillable]: la bandera la pone AdministradorSeeder.
     */
    public function esProtegida(): bool
    {
        return (bool) $this->protegido;
    }

    /**
     * Persona dueña de la cuenta. La identidad vive en `personas`; `users` solo agrega el
     * acceso administrativo.
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    /**
     * Rol único de la cuenta (§5.1). Un usuario no puede tener varios roles a la vez.
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    /**
     * ¿La cuenta otorga este permiso? (§5.3, §5.6)
     *
     * Se consulta contra el rol en el momento de la petición y nunca se copia a la cuenta:
     * así, al editar los permisos de un rol, sus usuarios los adquieren de inmediato (§5.4).
     *
     * Devuelve false si el rol está desactivado o si la cuenta o la persona están inactivas:
     * una persona dada de baja pierde todas sus capacidades (§3.3).
     */
    public function tienePermiso(string $clave): bool
    {
        if (! $this->puedeOperar()) {
            return false;
        }

        return $this->rol->permisos->contains('clave', $clave);
    }

    /**
     * ¿La cuenta está en condiciones de usarse?
     *
     * Son tres interruptores independientes y basta que uno esté apagado:
     *  - la persona fue dada de baja (§3.3),
     *  - la cuenta fue suspendida sin dar de baja a la persona (`users.activo`),
     *  - el rol fue desactivado (§5.5).
     *
     * El estado de la persona NO se copia a la cuenta: se lee del origen en cada petición,
     * para que no puedan quedar desincronizados.
     */
    public function puedeOperar(): bool
    {
        return $this->motivoBloqueo() === null;
    }

    /**
     * Por qué la cuenta no puede usarse, o null si sí puede.
     *
     * Vive junto a `puedeOperar()` para que el motivo y la decisión no puedan divergir: el
     * login rechaza y explica con la misma fuente.
     *
     * El orden importa poco para la lógica pero mucho para el mensaje: se reporta el
     * impedimento más de fondo primero, porque reactivar la cuenta de alguien que está dado
     * de baja no serviría de nada.
     */
    public function motivoBloqueo(): ?string
    {
        if ($this->persona?->estado !== 'ACTIVO') {
            return 'La persona está dada de baja, así que no puede iniciar sesión.';
        }

        if (! $this->activo) {
            return 'Esta cuenta está suspendida.';
        }

        if ($this->rol?->activo !== true) {
            return 'El rol de esta cuenta está desactivado, así que no tiene permisos efectivos.';
        }

        return null;
    }
}
