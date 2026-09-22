<?php

namespace App\Http\Resources;

use App\Models\GafeteDiseno;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Diseño del gafete (§4.1): dónde va cada dato y de dónde sale el fondo.
 *
 * El predeterminado del proyecto no está en la base: sale con `id` null y `predeterminado` true.
 * La ruta del archivo nunca se expone; el fondo se pide por `fondo_url`.
 *
 * @mixin GafeteDiseno
 */
class DisenoGafeteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->esPredeterminado() ? null : $this->id,
            'predeterminado' => $this->esPredeterminado(),
            'nombre_archivo' => $this->nombre_archivo,
            'activo' => $this->activo,
            'zonas' => $this->zonas,
            'advertencias' => $this->advertencias,
            'fondo_url' => $this->urlDeFondo(),
            'subido_por' => $this->whenLoaded('subidoPor', fn () => $this->subidoPor?->persona?->nombre_completo),
            'subido_en' => $this->created_at,
            'activado_en' => $this->activado_en,
        ];
    }
}
