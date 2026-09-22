<?php

namespace App\Models;

use App\Support\PlantillaGafete;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Diseño del gafete (§4.1): el fondo SVG y dónde va cada dato encima.
 *
 * Hay un solo formato para todos (§4). Un diseño subido nace como borrador y no se usa hasta que
 * alguien lo revisa y lo activa: uno mal armado dejaría de imprimir bien todos los gafetes al
 * instante. Mientras no haya uno activo se usa el predeterminado del proyecto, que no vive en la
 * base: vigente() lo devuelve como un modelo sin guardar.
 *
 * `archivo_path` va oculto, como la foto de la persona: el fondo solo sale por su endpoint.
 */
#[Fillable(['nombre_archivo', 'archivo_path', 'zonas', 'advertencias', 'subido_por', 'activo', 'activado_en'])]
#[Hidden(['archivo_path'])]
class GafeteDiseno extends Model
{
    public const DISCO = 'local';

    protected $table = 'gafete_disenos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activo' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'zonas' => 'array',
            'advertencias' => 'array',
            'activo' => 'boolean',
            'activado_en' => 'datetime',
        ];
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /**
     * El diseño con el que se imprimen los gafetes: el activo, o el predeterminado del proyecto.
     */
    public static function vigente(): self
    {
        return self::query()->where('activo', true)->first() ?? self::predeterminado();
    }

    /**
     * El diseño que trae el proyecto, como modelo sin guardar. Figura como activo solo si no hay
     * otro activo, pero eso lo decide vigente(): aquí siempre se arma igual.
     */
    public static function predeterminado(): self
    {
        $plantilla = PlantillaGafete::predeterminada();

        $diseno = new self([
            'nombre_archivo' => basename(PlantillaGafete::rutaPredeterminada()),
            'zonas' => $plantilla->zonas(),
            'advertencias' => $plantilla->advertencias(),
        ]);

        $diseno->activo = ! self::query()->where('activo', true)->exists();

        return $diseno;
    }

    public function esPredeterminado(): bool
    {
        return ! $this->exists;
    }

    /**
     * Lo pone en uso y deja a los demás como historial. Todos los renglones se bloquean mientras
     * tanto: dos activaciones a la vez dejarían dos diseños activos.
     */
    public function activar(): void
    {
        DB::transaction(function () {
            self::query()->lockForUpdate()->get(['id']);
            self::query()->whereKeyNot($this->getKey())->where('activo', true)->update(['activo' => false]);
            $this->forceFill(['activo' => true, 'activado_en' => now()])->save();
        });
    }

    /**
     * Vuelve al diseño del proyecto: ningún diseño subido queda activo.
     */
    public static function restablecerPredeterminado(): void
    {
        DB::transaction(function () {
            self::query()->lockForUpdate()->get(['id']);
            self::query()->where('activo', true)->update(['activo' => false]);
        });
    }

    /** El SVG del fondo, ya limpio y sin zonas. */
    public function fondo(): string
    {
        return $this->esPredeterminado()
            ? PlantillaGafete::predeterminada()->svg()
            : (string) Storage::disk(self::DISCO)->get($this->archivo_path);
    }

    /**
     * Dirección del fondo, con una versión para que el navegador no muestre el anterior de su
     * caché después de un cambio.
     */
    public function urlDeFondo(): string
    {
        if ($this->esPredeterminado()) {
            return '/api/gafetes/disenos/predeterminado/fondo?v='.substr(md5($this->fondo()), 0, 10);
        }

        return '/api/gafetes/disenos/'.$this->getKey().'/fondo?v='.substr(md5($this->archivo_path), 0, 10);
    }
}
