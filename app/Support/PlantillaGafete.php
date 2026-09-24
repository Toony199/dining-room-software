<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Diseño del gafete hecho como SVG en un editor (Illustrator, Inkscape, Figma) (§4.1).
 *
 * Quien diseña marca dónde va cada dato con cinco rectángulos: `zona-foto`, `zona-nombre`,
 * `zona-departamento`, `zona-numero` y `zona-qr`. De aquí salen dos cosas por separado:
 *
 *  - las zonas, en milímetros del gafete, para que la aplicación ponga encima la foto, los textos y
 *    el QR;
 *  - el fondo: el SVG limpio y ya sin las zonas.
 *
 * Seguridad. Un SVG puede traer código que se ejecuta en la sesión de quien lo abra. El fondo se
 * limpia con una lista blanca: solo pasan figuras, grupos, degradados, filtros, textos e imágenes
 * incrustadas; todo lo demás se quita (scripts, eventos, foreignObject, animaciones, enlaces a
 * otros sitios). No es la única defensa: la aplicación solo muestra el fondo como <img>, donde el
 * navegador no ejecuta nada ni carga nada externo, y el endpoint que lo sirve manda una CSP que
 * tampoco lo permite.
 */
final class PlantillaGafete
{
    public const ANCHO_MM = 54.0;

    public const ALTO_MM = 85.6;

    /** Diferencia de proporción admitida: lo que redondean los editores al exportar. */
    public const TOLERANCIA_PROPORCION = 0.01;

    /** Lado mínimo de la zona del QR, con su margen blanco: con menos, los lectores fallan. */
    public const QR_MINIMO_MM = 20.0;

    /** Cuánto puede salirse una zona del gafete por redondeo del editor. */
    private const TOLERANCIA_BORDE_MM = 0.5;

    public const ZONAS = [
        'zona-foto' => 'foto',
        'zona-nombre' => 'nombre',
        'zona-departamento' => 'departamento',
        'zona-numero' => 'numero',
        'zona-qr' => 'qr',
    ];

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Elementos que pasan. Lo que no está aquí se quita junto con su contenido.
     */
    private const ELEMENTOS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask',
        'image', 'style',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feFuncR', 'feFuncG',
        'feFuncB', 'feFuncA', 'feComposite', 'feDropShadow', 'feFlood', 'feGaussianBlur',
        'feMerge', 'feMergeNode', 'feMorphology', 'feOffset',
    ];

    /** Se quitan sin avisar: son datos del editor, no parte del dibujo. */
    private const ELEMENTOS_DEL_EDITOR = ['metadata'];

    /**
     * Atributos sin espacio de nombres que pasan. Ninguno puede ejecutar código; los que aceptan
     * referencias (`fill`, `clip-path`, `style`…) se revisan además en valorSeguro().
     */
    private const ATRIBUTOS = [
        'id', 'class', 'style', 'transform', 'version', 'viewBox', 'preserveAspectRatio',
        'x', 'y', 'width', 'height', 'rx', 'ry', 'cx', 'cy', 'r', 'x1', 'y1', 'x2', 'y2',
        'points', 'd', 'pathLength', 'href', 'type', 'media', 'enable-background',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'opacity', 'color', 'display', 'visibility', 'overflow',
        'paint-order', 'vector-effect', 'shape-rendering', 'text-rendering', 'image-rendering',
        'mix-blend-mode', 'isolation', 'clip-path', 'clip-rule', 'clipPathUnits', 'mask',
        'maskUnits', 'maskContentUnits',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy', 'fr', 'offset',
        'stop-color', 'stop-opacity', 'patternUnits', 'patternContentUnits', 'patternTransform',
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-stretch',
        'letter-spacing', 'word-spacing', 'text-anchor', 'dominant-baseline',
        'alignment-baseline', 'baseline-shift', 'text-decoration', 'dx', 'dy', 'rotate',
        'textLength', 'lengthAdjust',
        'filter', 'filterUnits', 'primitiveUnits', 'color-interpolation-filters', 'in', 'in2',
        'result', 'stdDeviation', 'mode', 'values', 'operator', 'k1', 'k2', 'k3', 'k4', 'radius',
        'flood-color', 'flood-opacity', 'lighting-color', 'tableValues', 'slope', 'intercept',
        'amplitude', 'exponent',
    ];

    /** Imágenes que se pueden incrustar (el logo, por ejemplo). Nunca enlazadas a un archivo. */
    private const IMAGEN_INCRUSTADA = '#^data:image/(png|jpe?g|webp);base64,[a-z0-9+/=\s]+$#i';

    /** Lo único que puede ir incrustado dentro de un estilo: imágenes y tipografías. */
    private const DATO_EN_CSS = '#^data:(image/(png|jpe?g|webp)|font/(woff2?|ttf|otf)|application/(font-woff2?|x-font-(ttf|otf)|font-sfnt|vnd\.ms-opentype));base64,[a-z0-9+/=]+$#i';

    private static ?self $predeterminada = null;

    /** @var array<string, true> */
    private array $eliminados = [];

    /** @var array<string, true> */
    private array $atributosConCodigo = [];

    private int $imagenesExternas = 0;

    private int $estilosInseguros = 0;

    /** @var array<string, true> */
    private array $zonasRedibujadas = [];

    private bool $fotoSinRedondeo = false;

    /** @var array<string, true> */
    private array $zonasSinColor = [];

    /**
     * Reglas de los <style> del documento, en orden: [selector, declaraciones].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $reglasCss = [];

    /**
     * @param  array<string, array{x: float, y: float, ancho: float, alto: float, color: ?string, radio: float}>  $zonas
     * @param  array<int, string>  $advertencias
     */
    private function __construct(
        private string $svg = '',
        private array $zonas = [],
        private array $advertencias = [],
    ) {}

    /**
     * Valida y limpia un SVG subido.
     *
     * @throws PlantillaGafeteInvalida si no sirve como diseño; el mensaje dice qué corregir.
     */
    public static function desdeContenido(string $contenido): self
    {
        $plantilla = new self;
        $documento = $plantilla->cargar($contenido);
        $raiz = $documento->documentElement;

        $plantilla->limpiarElemento($raiz);
        $plantilla->leerEstilos($documento);
        [$minX, $minY, $ancho, $alto] = $plantilla->medir($raiz);
        $plantilla->zonas = $plantilla->extraerZonas($documento, $minX, $minY, $ancho, $alto);
        $plantilla->advertencias = $plantilla->redactarAdvertencias($documento);

        // El fondo se estira exactamente al gafete, igual que se calcularon las zonas; la
        // diferencia de proporción admitida es de décimas de milímetro.
        $raiz->setAttribute('width', self::ANCHO_MM.'mm');
        $raiz->setAttribute('height', self::ALTO_MM.'mm');
        $raiz->setAttribute('preserveAspectRatio', 'none');

        $plantilla->svg = $documento->saveXML($raiz);

        return $plantilla;
    }

    /**
     * El diseño que trae el proyecto. Se usa mientras no se active uno subido, y es la plantilla
     * que se entrega a quien va a diseñar.
     */
    public static function predeterminada(): self
    {
        return self::$predeterminada ??= self::desdeContenido((string) file_get_contents(self::rutaPredeterminada()));
    }

    public static function rutaPredeterminada(): string
    {
        return resource_path('gafetes/plantilla-gafete.svg');
    }

    /** SVG del fondo: limpio y sin las zonas. */
    public function svg(): string
    {
        return $this->svg;
    }

    /**
     * Posición de cada dato en milímetros, desde la esquina superior izquierda del gafete.
     * `color` es el del texto (el relleno del rectángulo); `radio`, el de las esquinas de la foto.
     *
     * @return array<string, array{x: float, y: float, ancho: float, alto: float, color: ?string, radio: float}>
     */
    public function zonas(): array
    {
        return $this->zonas;
    }

    /**
     * Lo que conviene saber antes de activar el diseño aunque sea válido: qué se quitó y qué
     * puede salir distinto al imprimir.
     *
     * @return array<int, string>
     */
    public function advertencias(): array
    {
        return $this->advertencias;
    }

    // --- Lectura ---------------------------------------------------------------

    private function cargar(string $contenido): DOMDocument
    {
        if (trim($contenido) === '') {
            throw new PlantillaGafeteInvalida('El archivo está vacío.');
        }

        // Las entidades XML permiten leer archivos del servidor o inflar el documento hasta tumbarlo.
        // Ningún editor las necesita para exportar un dibujo.
        if (stripos($contenido, '<!ENTITY') !== false) {
            throw new PlantillaGafeteInvalida('El archivo declara entidades XML, que no se permiten. Expórtalo de nuevo como SVG normal.');
        }

        // Illustrator exporta con un DOCTYPE que solo apunta a la DTD pública. Se quita en vez de
        // rechazar el archivo; uno con subconjunto interno ([ … ]) ya se rechazó arriba o no llega.
        $contenido = (string) preg_replace('#<!DOCTYPE[^\[>]*>#i', '', $contenido);

        if (stripos($contenido, '<!DOCTYPE') !== false) {
            throw new PlantillaGafeteInvalida('El archivo trae una declaración DOCTYPE que no se permite. Expórtalo de nuevo como SVG normal.');
        }

        $documento = new DOMDocument;
        $anteriores = libxml_use_internal_errors(true);

        try {
            // Sin LIBXML_NOENT ni carga de DTD: nada de entidades, nada de red.
            $cargado = $documento->loadXML($contenido, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anteriores);
        }

        $raiz = $cargado ? $documento->documentElement : null;

        if (! $raiz || $raiz->localName !== 'svg' || $raiz->namespaceURI !== self::SVG_NS) {
            throw new PlantillaGafeteInvalida('El archivo no es un SVG válido.');
        }

        return $documento;
    }

    /**
     * Tamaño del dibujo en sus propias unidades. Da igual si son milímetros o píxeles: lo que
     * importa es la proporción, y de aquí sale la escala a milímetros del gafete.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function medir(DOMElement $raiz): array
    {
        $viewBox = preg_split('/[\s,]+/', trim($raiz->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY);

        if (count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3])) {
            [$minX, $minY, $ancho, $alto] = array_map('floatval', $viewBox);
        } else {
            // Sin viewBox, el tamaño sale de width/height. Se agrega el viewBox: sin él, el dibujo
            // no se escala al tamaño del gafete.
            [$minX, $minY] = [0.0, 0.0];
            $ancho = self::longitud($raiz->getAttribute('width'), aceptarUnidades: true);
            $alto = self::longitud($raiz->getAttribute('height'), aceptarUnidades: true);

            if ($ancho !== null && $alto !== null) {
                $raiz->setAttribute('viewBox', "0 0 {$ancho} {$alto}");
            }
        }

        if (($ancho ?? 0) <= 0 || ($alto ?? 0) <= 0) {
            throw new PlantillaGafeteInvalida('No se pudo saber el tamaño del diseño: el SVG no trae viewBox ni ancho y alto.');
        }

        $esperada = self::ANCHO_MM / self::ALTO_MM;
        $proporcion = $ancho / $alto;

        if (abs($proporcion / $esperada - 1) > self::TOLERANCIA_PROPORCION) {
            $detalle = abs((1 / $proporcion) / $esperada - 1) <= self::TOLERANCIA_PROPORCION
                ? 'Está en horizontal; el gafete va en vertical.'
                : sprintf('Mide %s × %s.', self::numero($ancho), self::numero($alto));

            throw new PlantillaGafeteInvalida("El diseño debe tener la proporción del gafete: 54 × 85.6 mm, en vertical. {$detalle}");
        }

        return [$minX, $minY, $ancho, $alto];
    }

    // --- Limpieza --------------------------------------------------------------

    private function limpiarElemento(DOMElement $elemento): void
    {
        $this->limpiarAtributos($elemento);

        if ($elemento->localName === 'style' && ! self::cssSeguro($elemento->textContent)) {
            $this->estilosInseguros++;
            $elemento->parentNode?->removeChild($elemento);

            return;
        }

        foreach (iterator_to_array($elemento->childNodes) as $hijo) {
            if ($hijo instanceof DOMElement) {
                if ($this->permitido($hijo)) {
                    $this->limpiarElemento($hijo);
                } else {
                    $elemento->removeChild($hijo);
                }
            } elseif ($hijo->nodeType === XML_PI_NODE || $hijo->nodeType === XML_COMMENT_NODE) {
                // Instrucciones de procesamiento (xml-stylesheet) cargan hojas de estilo externas;
                // los comentarios no se dibujan y el fondo no los necesita.
                $elemento->removeChild($hijo);
            }
        }
    }

    private function permitido(DOMElement $elemento): bool
    {
        // Lo de otros espacios de nombres (sodipodi:namedview de Inkscape, capas de Illustrator)
        // no se dibuja: se quita sin avisar.
        if ($elemento->namespaceURI !== self::SVG_NS) {
            return false;
        }

        $nombre = $elemento->localName;

        if (in_array($nombre, self::ELEMENTOS_DEL_EDITOR, true)) {
            return false;
        }

        if (! in_array($nombre, self::ELEMENTOS, true)) {
            $this->eliminados[$nombre] = true;

            return false;
        }

        if ($nombre === 'image' && ! preg_match(self::IMAGEN_INCRUSTADA, self::href($elemento))) {
            $this->imagenesExternas++;

            return false;
        }

        return true;
    }

    private function limpiarAtributos(DOMElement $elemento): void
    {
        /** @var array<int, DOMAttr> $atributos */
        $atributos = iterator_to_array($elemento->attributes);

        foreach ($atributos as $atributo) {
            if (! $this->atributoPermitido($elemento, $atributo)) {
                $elemento->removeAttributeNode($atributo);
            }
        }
    }

    private function atributoPermitido(DOMElement $elemento, DOMAttr $atributo): bool
    {
        $espacio = $atributo->namespaceURI;
        $nombre = $atributo->localName;
        $valor = $atributo->value;

        if ($espacio === self::XML_NS) {
            return $nombre === 'space';
        }

        if ($espacio === self::XLINK_NS || ($espacio === null && $nombre === 'href')) {
            if ($nombre !== 'href') {
                return false;
            }

            // Una imagen puede ir incrustada; cualquier otra referencia solo apunta a un id de
            // este mismo SVG (degradados, <use>).
            return $elemento->localName === 'image'
                ? (bool) preg_match(self::IMAGEN_INCRUSTADA, $valor)
                : str_starts_with(trim($valor), '#');
        }

        // Atributos de editores (inkscape:label, data-name de Illustrator…): se quitan sin avisar.
        if ($espacio !== null) {
            return false;
        }

        if (str_starts_with(strtolower($nombre), 'on')) {
            $this->atributosConCodigo[$nombre] = true;

            return false;
        }

        if (! in_array($nombre, self::ATRIBUTOS, true)) {
            return false;
        }

        if ($nombre === 'style') {
            if (! self::cssSeguro($valor)) {
                $this->estilosInseguros++;

                return false;
            }

            return true;
        }

        return self::valorSeguro($valor);
    }

    /**
     * Un valor de atributo solo puede referirse a otra parte del mismo SVG: `url(#degradado)`.
     */
    private static function valorSeguro(string $valor): bool
    {
        $plano = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', $valor));

        if (preg_match('/(java|vb)script:|data:/', $plano)) {
            return false;
        }

        return self::urlsInternas($plano, permitirDatos: false);
    }

    /**
     * Un estilo no puede importar hojas, ejecutar expresiones ni cargar nada de fuera; sí puede
     * traer imágenes y tipografías incrustadas.
     */
    private static function cssSeguro(string $css): bool
    {
        // Los escapes de CSS (\75 rl) sirven para disfrazar url( y @import. Un editor no los usa.
        if (str_contains($css, '\\')) {
            return false;
        }

        $plano = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', $css));

        foreach (['@import', 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding'] as $peligroso) {
            if (str_contains($plano, $peligroso)) {
                return false;
            }
        }

        return self::urlsInternas($plano, permitirDatos: true);
    }

    private static function urlsInternas(string $plano, bool $permitirDatos): bool
    {
        preg_match_all('/url\(([^)]*)\)/', $plano, $coincidencias);

        foreach ($coincidencias[1] as $destino) {
            $destino = trim($destino, '\'"');

            if (str_starts_with($destino, '#')) {
                continue;
            }

            if ($permitirDatos && preg_match(self::DATO_EN_CSS, $destino)) {
                continue;
            }

            return false;
        }

        return true;
    }

    // --- Zonas -----------------------------------------------------------------

    /**
     * @return array<string, array{x: float, y: float, ancho: float, alto: float, color: ?string, radio: float}>
     */
    private function extraerZonas(DOMDocument $documento, float $minX, float $minY, float $ancho, float $alto): array
    {
        $xpath = new DOMXPath($documento);
        $escalaX = self::ANCHO_MM / $ancho;
        $escalaY = self::ALTO_MM / $alto;
        $zonas = [];

        foreach (self::ZONAS as $id => $clave) {
            $encontrados = $xpath->query("//*[@id='{$id}']");

            if (! $encontrados || $encontrados->length === 0) {
                throw new PlantillaGafeteInvalida("Falta la zona «{$id}»: dibuja un rectángulo con ese id donde va ".self::descripcion($clave).'.');
            }

            if ($encontrados->length > 1) {
                throw new PlantillaGafeteInvalida("La zona «{$id}» aparece {$encontrados->length} veces: debe haber una sola.");
            }

            /** @var DOMElement $figura */
            $figura = $encontrados->item(0);

            [$a, $d, $e, $f] = $this->transformacion($figura, $id);
            [$x, $y, $w, $h] = $this->rectanguloDe($figura, $id);

            // Solo traslación y escala (ya se descartaron giros e inclinaciones): las esquinas
            // opuestas bastan.
            $x1 = $a * $x + $e;
            $x2 = $a * ($x + $w) + $e;
            $y1 = $d * $y + $f;
            $y2 = $d * ($y + $h) + $f;

            $zona = [
                'x' => round((min($x1, $x2) - $minX) * $escalaX, 2),
                'y' => round((min($y1, $y2) - $minY) * $escalaY, 2),
                'ancho' => round(abs($x2 - $x1) * $escalaX, 2),
                'alto' => round(abs($y2 - $y1) * $escalaY, 2),
                'color' => $this->colorDeRelleno($figura),
                'radio' => round(abs($a) * $this->radioDe($figura, $x, $y, $w, $h) * $escalaX, 2),
            ];

            $this->comprobarLimites($id, $zona);

            // Si la foto venía como trazo y no se le pudo deducir el redondeo, sale con las
            // esquinas rectas: conviene decirlo.
            if ($clave === 'foto' && $zona['radio'] == 0.0 && isset($this->zonasRedibujadas[$id])) {
                $this->fotoSinRedondeo = true;
            }

            // Las zonas de la foto y del QR no llevan texto: su relleno da igual.
            if ($zona['color'] === null && ! in_array($clave, ['foto', 'qr'], true)) {
                $this->zonasSinColor[$id] = true;
            }

            $zonas[$clave] = $zona;

            // El fondo no lleva las zonas: encima de ellas va el dato real.
            $figura->parentNode?->removeChild($figura);
        }

        $ladoQr = min($zonas['qr']['ancho'], $zonas['qr']['alto']);

        if ($ladoQr < self::QR_MINIMO_MM) {
            throw new PlantillaGafeteInvalida(sprintf(
                'La zona del QR mide %s × %s mm: debe medir al menos %s × %s mm para que los lectores del kiosco y del comedor lo reconozcan.',
                self::numero($zonas['qr']['ancho']),
                self::numero($zonas['qr']['alto']),
                self::numero(self::QR_MINIMO_MM),
                self::numero(self::QR_MINIMO_MM),
            ));
        }

        return $zonas;
    }

    /**
     * Rectángulo que ocupa la zona, en las unidades del dibujo: [x, y, ancho, alto].
     *
     * Lo natural es marcarla con un rectángulo, pero los editores guardan como trazo los
     * rectángulos con esquinas redondeadas, así que también se acepta un trazo (y las demás figuras)
     * y se usa el rectángulo que la encierra.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rectanguloDe(DOMElement $figura, string $id): array
    {
        $n = fn (string $atributo, string $porDefecto = '0'): ?float => self::longitud($figura->getAttribute($atributo) ?: $porDefecto);

        $caja = match ($figura->localName) {
            'rect' => [$n('x'), $n('y'), $n('width', ''), $n('height', '')],
            'circle' => self::cajaDeElipse($n('cx'), $n('cy'), $n('r', ''), $n('r', '')),
            'ellipse' => self::cajaDeElipse($n('cx'), $n('cy'), $n('rx', ''), $n('ry', '')),
            'path' => self::cajaDeTrazo($figura->getAttribute('d')),
            'polygon', 'polyline' => self::cajaDePuntos($figura->getAttribute('points')),
            default => throw new PlantillaGafeteInvalida(
                "La zona «{$id}» debe ser un rectángulo (o el trazo de uno), no un «{$figura->localName}»."
            ),
        };

        if ($caja === null || in_array(null, $caja, true) || $caja[2] <= 0 || $caja[3] <= 0) {
            throw new PlantillaGafeteInvalida("La zona «{$id}» no tiene una posición y un tamaño que se puedan leer.");
        }

        if ($figura->localName !== 'rect') {
            $this->zonasRedibujadas[$id] = true;
        }

        /** @var array{0: float, 1: float, 2: float, 3: float} $caja */
        return $caja;
    }

    /**
     * Radio de las esquinas, que solo usa la foto para recortarla.
     *
     * Un rectángulo lo declara en `rx`. Un trazo no, pero el redondeo sigue dibujado en él: el editor
     * lo convirtió en arcos (Illustrator, Inkscape) o en curvas (Figma), y de ahí se deduce. Así la
     * foto conserva sus esquinas aunque el editor haya convertido la zona.
     */
    private function radioDe(DOMElement $figura, float $x, float $y, float $ancho, float $alto): float
    {
        if ($figura->localName === 'rect') {
            return self::longitud($figura->getAttribute('rx') ?: $figura->getAttribute('ry') ?: '0') ?? 0.0;
        }

        if ($figura->localName !== 'path') {
            return 0.0;
        }

        $radio = self::radioDelTrazo($figura->getAttribute('d'), $x, $y, $ancho, $alto);

        // Un radio absurdo (la figura no era un rectángulo redondeado) se descarta en vez de
        // deformar la foto.
        return $radio > 0 && $radio <= min($ancho, $alto) / 2 ? $radio : 0.0;
    }

    /**
     * Redondeo que dibuja un trazo en sus esquinas, o 0 si no se reconoce.
     */
    private static function radioDelTrazo(string $d, float $x, float $y, float $ancho, float $alto): float
    {
        // Illustrator e Inkscape dibujan la esquina con un arco, y su radio es el del redondeo.
        if (preg_match('/[Aa]\s*(-?[\d.]+)[\s,]+(-?[\d.]+)/', $d, $arco)) {
            [$rx, $ry] = [abs((float) $arco[1]), abs((float) $arco[2])];

            if ($rx > 0 && abs($rx - $ry) < 0.01) {
                return $rx;
            }
        }

        // Figma usa curvas: el trazo arranca sobre un lado, a la distancia del radio desde la
        // esquina. Se mide esa separación.
        if (! preg_match('/^\s*[Mm]\s*(-?[\d.]+)[\s,]+(-?[\d.]+)/', $d, $inicio)) {
            return 0.0;
        }

        [$px, $py] = [(float) $inicio[1], (float) $inicio[2]];
        $aLosLados = min(abs($px - $x), abs($x + $ancho - $px));
        $aArribaYAbajo = min(abs($py - $y), abs($y + $alto - $py));

        // El punto de arranque está sobre un lado: pegado a un borde y separado del otro por el
        // radio. Si no cumple eso, la figura no es un rectángulo redondeado.
        return match (true) {
            $aArribaYAbajo < 0.01 => $aLosLados,
            $aLosLados < 0.01 => $aArribaYAbajo,
            default => 0.0,
        };
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?float}
     */
    private static function cajaDeElipse(?float $cx, ?float $cy, ?float $rx, ?float $ry): array
    {
        if ($cx === null || $cy === null || $rx === null || $ry === null) {
            return [null, null, null, null];
        }

        return [$cx - $rx, $cy - $ry, 2 * $rx, 2 * $ry];
    }

    /**
     * Rectángulo que encierra un trazo. Se toman los puntos del recorrido y los de control de las
     * curvas: para un rectángulo con esquinas redondeadas, que es de lo que se trata, los puntos de
     * control caen dentro de las esquinas y la medida sale exacta.
     *
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?float}|null
     */
    private static function cajaDeTrazo(string $d): ?array
    {
        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])([^MmLlHhVvCcSsQqTtAaZz]*)/', $d, $comandos, PREG_SET_ORDER);

        if (! $comandos) {
            return null;
        }

        $xs = [];
        $ys = [];
        [$x, $y, $inicioX, $inicioY] = [0.0, 0.0, 0.0, 0.0];

        foreach ($comandos as [, $comando, $argumentos]) {
            preg_match_all('/-?\d*\.?\d+(?:e[-+]?\d+)?/i', $argumentos, $numeros);
            $n = array_map('floatval', $numeros[0]);
            $relativo = $comando === strtolower($comando);
            $letra = strtoupper($comando);

            if ($letra === 'Z') {
                [$x, $y] = [$inicioX, $inicioY];

                continue;
            }

            // Cuántos números consume cada comando y en qué posición de ellos va el punto final.
            $tamano = match ($letra) {
                'M', 'L', 'T' => 2,
                'H', 'V' => 1,
                'S', 'Q' => 4,
                'C' => 6,
                'A' => 7,
                default => 0,
            };

            if ($tamano === 0) {
                continue;
            }

            foreach (array_chunk($n, $tamano) as $grupo) {
                if (count($grupo) < $tamano) {
                    break;
                }

                if ($letra === 'H') {
                    $x = $relativo ? $x + $grupo[0] : $grupo[0];
                } elseif ($letra === 'V') {
                    $y = $relativo ? $y + $grupo[0] : $grupo[0];
                } else {
                    // Los pares intermedios son puntos de control; el último, el destino. En el arco
                    // los cinco primeros números son radios y banderas, no coordenadas.
                    $pares = $letra === 'A'
                        ? [[$grupo[5], $grupo[6]]]
                        : array_chunk($grupo, 2);

                    foreach ($pares as $par) {
                        $px = $relativo ? $x + $par[0] : $par[0];
                        $py = $relativo ? $y + $par[1] : $par[1];
                        $xs[] = $px;
                        $ys[] = $py;
                    }

                    [$x, $y] = [$px, $py];
                }

                $xs[] = $x;
                $ys[] = $y;

                if ($letra === 'M') {
                    [$inicioX, $inicioY] = [$x, $y];
                    // Los pares que siguen a una M son líneas, no más movimientos.
                    $letra = 'L';
                }
            }
        }

        return $xs ? [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)] : null;
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?float}|null
     */
    private static function cajaDePuntos(string $puntos): ?array
    {
        preg_match_all('/-?\d*\.?\d+(?:e[-+]?\d+)?/i', $puntos, $numeros);
        $n = array_map('floatval', $numeros[0]);

        if (count($n) < 4) {
            return null;
        }

        $xs = [];
        $ys = [];

        foreach (array_chunk($n, 2) as $par) {
            if (count($par) === 2) {
                $xs[] = $par[0];
                $ys[] = $par[1];
            }
        }

        return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
    }

    /**
     * Transformación acumulada de la zona (la suya y la de los grupos que la contienen), como
     * escala y traslación: [a, d, e, f] de matrix(a 0 0 d e f).
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function transformacion(DOMElement $rect, string $id): array
    {
        $cadena = [];

        for ($nodo = $rect; $nodo instanceof DOMElement && ! $nodo->isSameNode($nodo->ownerDocument->documentElement); $nodo = $nodo->parentNode) {
            if (! $nodo->isSameNode($rect) && $nodo->localName === 'svg') {
                throw new PlantillaGafeteInvalida("La zona «{$id}» está dentro de un SVG anidado: muévela al dibujo principal.");
            }

            array_unshift($cadena, $nodo->getAttribute('transform'));
        }

        [$a, $d, $e, $f] = [1.0, 1.0, 0.0, 0.0];

        foreach ($cadena as $transform) {
            foreach (self::matrices($transform, $id) as [$ta, $td, $te, $tf]) {
                // [a 0 e; 0 d f] × [ta 0 te; 0 td tf]
                [$a, $d, $e, $f] = [$a * $ta, $d * $td, $a * $te + $e, $d * $tf + $f];
            }
        }

        return [$a, $d, $e, $f];
    }

    /**
     * @return array<int, array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function matrices(string $transform, string $id): array
    {
        $girada = new PlantillaGafeteInvalida("La zona «{$id}» está girada o inclinada: debe quedar derecha, sin rotación.");

        if (trim($transform) === '') {
            return [];
        }

        preg_match_all('/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/', $transform, $funciones, PREG_SET_ORDER);

        if (! $funciones) {
            throw new PlantillaGafeteInvalida("La zona «{$id}» tiene una transformación que no se puede leer.");
        }

        $matrices = [];

        foreach ($funciones as [, $funcion, $argumentos]) {
            $n = array_map('floatval', preg_split('/[\s,]+/', trim($argumentos), -1, PREG_SPLIT_NO_EMPTY));

            $matrices[] = match ($funcion) {
                'translate' => [1.0, 1.0, $n[0] ?? 0.0, $n[1] ?? 0.0],
                'scale' => [$n[0] ?? 1.0, $n[1] ?? ($n[0] ?? 1.0), 0.0, 0.0],
                'rotate' => fmod($n[0] ?? 0.0, 360.0) == 0.0 ? [1.0, 1.0, 0.0, 0.0] : throw $girada,
                'skewX', 'skewY' => ($n[0] ?? 0.0) == 0.0 ? [1.0, 1.0, 0.0, 0.0] : throw $girada,
                'matrix' => count($n) === 6 && abs($n[1]) < 1e-6 && abs($n[2]) < 1e-6
                    ? [$n[0], $n[3], $n[4], $n[5]]
                    : throw $girada,
            };
        }

        return $matrices;
    }

    /**
     * @param  array{x: float, y: float, ancho: float, alto: float, color: ?string, radio: float}  $zona
     */
    private function comprobarLimites(string $id, array $zona): void
    {
        $t = self::TOLERANCIA_BORDE_MM;

        if ($zona['x'] < -$t || $zona['y'] < -$t
            || self::ANCHO_MM + $t < $zona['x'] + $zona['ancho']
            || self::ALTO_MM + $t < $zona['y'] + $zona['alto']) {
            throw new PlantillaGafeteInvalida("La zona «{$id}» se sale del gafete: acomódala dentro del diseño.");
        }
    }

    /**
     * Color del texto: el relleno del rectángulo de la zona, sin su transparencia (en la plantilla
     * van semitransparentes para que se vean al diseñar). Null si no tiene uno legible.
     */
    private function colorDeRelleno(DOMElement $figura): ?string
    {
        // Orden de la cascada: el estilo propio gana, luego lo que digan los <style> del documento
        // (donde Illustrator deja el color al exportar con CSS interno) y al final el atributo fill.
        foreach ([
            self::fillDeCss($figura->getAttribute('style')),
            $this->fillDeLasReglas($figura),
            trim($figura->getAttribute('fill')),
        ] as $candidato) {
            $color = self::colorLegible((string) $candidato);

            if ($color !== null) {
                return $color;
            }
        }

        return null;
    }

    /**
     * Declaración `fill` dentro de un bloque de CSS.
     */
    private static function fillDeCss(string $css): ?string
    {
        return preg_match('/(?:^|;)\s*fill\s*:\s*([^;]+)/i', $css, $coincidencia)
            ? trim($coincidencia[1])
            : null;
    }

    /**
     * Color que le toca a la figura por los <style> del documento. Se recorren las reglas de menos a
     * más específica (etiqueta, clase, id) y dentro de cada grupo gana la última, que es como
     * resuelve el navegador las hojas que escriben los editores.
     */
    private function fillDeLasReglas(DOMElement $figura): ?string
    {
        if (! $this->reglasCss) {
            return null;
        }

        $id = $figura->getAttribute('id');
        $clases = preg_split('/\s+/', trim($figura->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $porGrupo = [1 => null, 2 => null, 3 => null];

        foreach ($this->reglasCss as [$selector, $declaraciones]) {
            $grupo = match (true) {
                $id !== '' && $selector === '#'.$id => 3,
                $selector !== '' && $selector[0] === '.' && in_array(substr($selector, 1), $clases, true) => 2,
                $selector === $figura->localName => 1,
                default => 0,
            };

            if ($grupo !== 0 && ($fill = self::fillDeCss($declaraciones)) !== null) {
                $porGrupo[$grupo] = $fill;
            }
        }

        return $porGrupo[3] ?? $porGrupo[2] ?? $porGrupo[1];
    }

    /**
     * Guarda las reglas de los <style> del documento. Es un CSS de editor de dibujo: selectores
     * simples separados por comas, sin anidar.
     */
    private function leerEstilos(DOMDocument $documento): void
    {
        foreach ($documento->getElementsByTagNameNS(self::SVG_NS, 'style') as $estilo) {
            preg_match_all('/([^{}]+)\{([^}]*)\}/', $estilo->textContent, $bloques, PREG_SET_ORDER);

            foreach ($bloques as [, $selectores, $declaraciones]) {
                foreach (explode(',', $selectores) as $selector) {
                    $this->reglasCss[] = [trim($selector), $declaraciones];
                }
            }
        }
    }

    /**
     * Normaliza un color a algo que el navegador entienda, o null si no es un color de verdad.
     * Se admite la transparencia que agregan algunos editores (#rrggbbaa), pero se descarta: el
     * texto de un gafete se lee, no se difumina.
     */
    private static function colorLegible(string $valor): ?string
    {
        $color = strtolower(trim($valor));

        if ($color === '' || in_array($color, ['none', 'transparent', 'currentcolor', 'inherit'], true)) {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $color)) {
            // #rgba y #rrggbbaa: se quedan los dígitos del color, sin los de la transparencia.
            return match (strlen($color)) {
                5 => substr($color, 0, 4),
                9 => substr($color, 0, 7),
                default => $color,
            };
        }

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,[^)]+)?\)$/', $color, $n)) {
            return "rgb({$n[1]}, {$n[2]}, {$n[3]})";
        }

        // Colores con nombre (red, navy…). Se descartan las funciones y las referencias url(#…).
        return preg_match('/^[a-z]{3,20}$/', $color) ? $color : null;
    }

    // --- Avisos ----------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function redactarAdvertencias(DOMDocument $documento): array
    {
        $avisos = [];

        if ($this->eliminados) {
            $avisos[] = 'Se quitaron elementos que no se permiten en el diseño: '.implode(', ', array_keys($this->eliminados)).'.';
        }

        if ($this->atributosConCodigo) {
            $avisos[] = 'Se quitaron atributos que ejecutan código: '.implode(', ', array_keys($this->atributosConCodigo)).'.';
        }

        if ($this->imagenesExternas) {
            $avisos[] = $this->imagenesExternas === 1
                ? 'Se quitó una imagen enlazada a un archivo externo: incrústala en el SVG.'
                : "Se quitaron {$this->imagenesExternas} imágenes enlazadas a archivos externos: incrústalas en el SVG.";
        }

        if ($this->estilosInseguros) {
            $avisos[] = 'Se quitaron estilos que cargaban recursos externos o traían código.';
        }

        if ($this->zonasSinColor) {
            $avisos[] = 'No se pudo leer un color de relleno en '
                .(count($this->zonasSinColor) === 1 ? 'la zona ' : 'las zonas ')
                .implode(', ', array_keys($this->zonasSinColor))
                .': ese texto saldrá en negro. Píntalas con un color plano (no con degradado) del color que quieras las letras.';
        }

        if ($this->zonasRedibujadas) {
            $avisos[] = 'El editor guardó como trazo '.(count($this->zonasRedibujadas) === 1 ? 'la zona' : 'las zonas')
                .' '.implode(', ', array_keys($this->zonasRedibujadas))
                .': se usó el rectángulo que la encierra.';
        }

        if ($this->fotoSinRedondeo) {
            $avisos[] = 'No se pudo deducir el redondeo de las esquinas de zona-foto: la fotografía saldrá'
                .' con las esquinas rectas. Para redondearlas, deja esa zona como un rectángulo con'
                .' esquinas redondeadas en lugar de convertirla a trazo.';
        }

        $textos = $documento->getElementsByTagNameNS(self::SVG_NS, 'text')->length;

        if ($textos) {
            $avisos[] = ($textos === 1 ? 'El diseño tiene un texto' : "El diseño tiene {$textos} textos")
                .' sin convertir a curvas: si la computadora que imprime no tiene esa tipografía, saldrán con otra.';
        }

        return $avisos;
    }

    // --- Utilidades ------------------------------------------------------------

    /**
     * Número de un atributo de longitud: sin unidades o en px. Con $aceptarUnidades (solo para el
     * tamaño del SVG raíz) también mm, cm, in, pt y pc: lo que importa ahí es la proporción.
     */
    private static function longitud(string $valor, bool $aceptarUnidades = false): ?float
    {
        $patron = $aceptarUnidades
            ? '/^\s*(-?\d*\.?\d+(?:e[-+]?\d+)?)\s*(px|mm|cm|in|pt|pc)?\s*$/i'
            : '/^\s*(-?\d*\.?\d+(?:e[-+]?\d+)?)\s*(px)?\s*$/i';

        return preg_match($patron, $valor, $m) ? (float) $m[1] : null;
    }

    private static function href(DOMElement $elemento): string
    {
        return $elemento->getAttributeNS(self::XLINK_NS, 'href') ?: $elemento->getAttribute('href');
    }

    private static function descripcion(string $clave): string
    {
        return match ($clave) {
            'foto' => 'la fotografía',
            'nombre' => 'el nombre',
            'departamento' => 'el departamento',
            'numero' => 'el número de empleado',
            'qr' => 'el código QR',
        };
    }

    private static function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }
}
