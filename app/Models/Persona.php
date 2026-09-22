<?php

namespace App\Models;

use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Fillable(['numero_empleado', 'nombre', 'primer_apellido', 'segundo_apellido', 'departamento_id', 'foto_path', 'estado'])]
class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use HasFactory;

    /**
     * Disco de las fotografías (§3.1): el privado, fuera de `public/`. Son datos personales y
     * solo se entregan por el API, a quien tiene sesión y permiso.
     */
    public const DISCO_FOTOS = 'local';

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
     * Todos los gafetes emitidos a la persona, del más reciente al más antiguo. Los reemplazados
     * se conservan como historial (§4.3).
     */
    public function gafetes(): HasMany
    {
        return $this->hasMany(Gafete::class)->latest('emitido_en')->latest('id');
    }

    /**
     * El gafete que funciona hoy. A lo sumo hay uno (§4.3); `null` si nunca se le emitió.
     */
    public function gafeteActivo(): HasOne
    {
        return $this->hasOne(Gafete::class)->where('estado', 'ACTIVO');
    }

    /**
     * Emite un gafete nuevo y deja inactivo el anterior, si lo había (§4.3, RN-08).
     *
     * Todo ocurre en una transacción con la fila de la persona bloqueada: dos emisiones
     * simultáneas (dos administrativos, un doble clic) no deben dejar dos gafetes ACTIVO.
     *
     * No valida el estado de la persona: el alta la crea ACTIVA, y el endpoint de emisión es
     * quien rechaza a las personas dadas de baja.
     */
    public function emitirGafete(): Gafete
    {
        return DB::transaction(function () {
            Persona::whereKey($this->getKey())->lockForUpdate()->first();

            Gafete::where('persona_id', $this->getKey())
                ->where('estado', 'ACTIVO')
                ->update(['estado' => 'INACTIVO']);

            return Gafete::create([
                'persona_id' => $this->getKey(),
                // Token opaco: no deriva del número de empleado ni de ningún dato legible, así
                // que no se puede adivinar ni reconstruir a partir de lo impreso.
                'qr_token' => (string) Str::ulid(),
                'estado' => 'ACTIVO',
                'emitido_en' => now(),
            ]);
        });
    }

    /**
     * ¿Tiene una fotografía guardada? `filled` descarta NULL y la cadena vacía; comprobar el
     * archivo cubre rutas que apuntan a algo que ya no existe.
     */
    public function tieneFoto(): bool
    {
        return filled($this->foto_path) && Storage::disk(self::DISCO_FOTOS)->exists($this->foto_path);
    }

    /**
     * URL del API que entrega la fotografía, o null si no tiene. La ruta del archivo nunca sale
     * del servidor. `v` cambia con cada foto nueva para que el navegador no muestre la anterior
     * desde su caché.
     */
    public function urlDeFoto(): ?string
    {
        if (! $this->tieneFoto()) {
            return null;
        }

        return '/api/personas/'.$this->getKey().'/foto?v='.substr(md5($this->foto_path), 0, 10);
    }

    /**
     * Asigna o reemplaza la fotografía (§3.1).
     *
     * El archivo nuevo se escribe antes de tocar la base, y el anterior se borra solo cuando la
     * transacción confirma: si algo falla, la persona conserva la foto que tenía y no queda
     * huérfano el archivo nuevo. No toca el gafete: el QR no depende de la foto.
     */
    public function asignarFoto(UploadedFile $foto): void
    {
        $disco = Storage::disk(self::DISCO_FOTOS);
        $anterior = $this->foto_path;

        // Nombre aleatorio: la ruta no dice de quién es la foto ni se puede adivinar.
        $nueva = $foto->storeAs(
            'fotos/personas/'.$this->getKey(),
            Str::ulid().'.'.$foto->extension(),
            self::DISCO_FOTOS,
        );

        if ($nueva === false) {
            throw new RuntimeException('No se pudo guardar la fotografía.');
        }

        try {
            DB::transaction(fn () => $this->forceFill(['foto_path' => $nueva])->save());
        } catch (Throwable $e) {
            $disco->delete($nueva);

            throw $e;
        }

        if (filled($anterior)) {
            DB::afterCommit(fn () => $disco->delete($anterior));
        }
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

    /**
     * Nombre que se imprime en el gafete (§4.1): nombre y primer apellido. El gafete no necesita
     * el nombre completo para identificar a nadie, eso lo hacen el QR y la foto, y así cabe en
     * una o dos líneas sin achicarse hasta ser ilegible.
     *
     * @return Attribute<string, never>
     */
    protected function nombreGafete(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->nombre,
            $this->primer_apellido,
        ])));
    }
}
