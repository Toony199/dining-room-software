<?php

namespace Tests\Unit;

use App\Support\PlantillaGafete;
use App\Support\PlantillaGafeteInvalida;
use Tests\TestCase;

/**
 * Lectura y limpieza del SVG del diseño del gafete (§4.1).
 *
 * La limpieza es una defensa entre tres (ver PlantillaGafete), pero es la que decide qué queda
 * guardado en el servidor: cada caso de aquí es algo que un SVG puede traer para ejecutar código
 * o cargar recursos de fuera.
 */
class PlantillaGafeteTest extends TestCase
{
    /** PNG de 1 × 1, para probar un logo incrustado. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * Las cinco zonas en milímetros, multiplicadas por $escala (para diseños en píxeles). Una zona
     * con valor null se omite; con texto, se reemplaza por ese marcado.
     *
     * @param  array<string, ?string>  $cambios
     */
    private function zonas(float $escala = 1.0, array $cambios = []): string
    {
        $base = [
            'zona-foto' => [17, 10, 20, 26.67, 'rx="1.6" fill="#1e40af"'],
            'zona-nombre' => [3, 39.5, 48, 8, 'fill="#ffffff"'],
            'zona-departamento' => [3, 47.8, 48, 4, 'fill="#525252"'],
            'zona-numero' => [3, 52.1, 48, 4, 'fill="#000000"'],
            'zona-qr' => [16, 59.6, 22, 22, 'fill="#1e40af"'],
        ];

        $marcado = '';

        foreach ($base as $id => [$x, $y, $w, $h, $extra]) {
            if (array_key_exists($id, $cambios)) {
                $marcado .= $cambios[$id] ?? '';

                continue;
            }

            $extra = $id === 'zona-foto' ? 'rx="'.(1.6 * $escala).'" fill="#1e40af"' : $extra;
            $marcado .= sprintf('<rect id="%s" x="%s" y="%s" width="%s" height="%s" %s/>', $id, $x * $escala, $y * $escala, $w * $escala, $h * $escala, $extra);
        }

        return $marcado;
    }

    private function svg(string $dibujo = '', ?string $zonas = null, string $raiz = 'viewBox="0 0 54 85.6"'): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '.$raiz.'>'
            .$dibujo.($zonas ?? $this->zonas()).'</svg>';
    }

    private function leer(string $svg): PlantillaGafete
    {
        return PlantillaGafete::desdeContenido($svg);
    }

    private function assertRechaza(string $svg, string $mensaje): void
    {
        try {
            $this->leer($svg);
            $this->fail('Se aceptó un diseño que debía rechazarse.');
        } catch (PlantillaGafeteInvalida $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());
        }
    }

    // --- La plantilla del proyecto ---------------------------------------------

    public function test_la_plantilla_del_proyecto_es_un_diseno_valido(): void
    {
        $plantilla = PlantillaGafete::predeterminada();

        $this->assertSame(array_values(PlantillaGafete::ZONAS), array_keys($plantilla->zonas()));
        $this->assertSame(['x' => 16.0, 'y' => 59.6, 'ancho' => 22.0, 'alto' => 22.0], array_intersect_key(
            $plantilla->zonas()['qr'],
            array_flip(['x', 'y', 'ancho', 'alto']),
        ));
        $this->assertStringNotContainsString('id="zona-', $plantilla->svg());
    }

    // --- Zonas -------------------------------------------------------------------

    public function test_convierte_las_zonas_a_milimetros_aunque_el_diseno_este_en_pixeles(): void
    {
        // Figma exporta en px: 54 × 85.6 mm son 204.09 × 323.53 px a 96 ppp.
        $px = 96 / 25.4;
        $plantilla = $this->leer($this->svg('', $this->zonas($px), sprintf('viewBox="0 0 %s %s"', 54 * $px, 85.6 * $px)));

        $qr = $plantilla->zonas()['qr'];
        $this->assertEqualsWithDelta(16.0, $qr['x'], 0.01);
        $this->assertEqualsWithDelta(59.6, $qr['y'], 0.01);
        $this->assertEqualsWithDelta(22.0, $qr['ancho'], 0.01);
        $this->assertEqualsWithDelta(1.6, $plantilla->zonas()['foto']['radio'], 0.01);
    }

    public function test_respeta_un_viewbox_que_no_empieza_en_cero(): void
    {
        // El dibujo entero está desplazado (100, 200): las zonas se miden desde la esquina del
        // viewBox, no desde el origen.
        $zonas = '<g transform="translate(100 200)">'.$this->zonas().'</g>';

        $plantilla = $this->leer($this->svg('', $zonas, 'viewBox="100 200 54 85.6"'));

        $this->assertSame(16.0, $plantilla->zonas()['qr']['x']);
        $this->assertSame(59.6, $plantilla->zonas()['qr']['y']);
    }

    public function test_aplica_las_transformaciones_de_los_grupos(): void
    {
        // Los editores agrupan y escalan: la zona vale donde se ve, no donde dice su x/y.
        $plantilla = $this->leer($this->svg('', $this->zonas(1, [
            'zona-qr' => '<g transform="translate(10 5)"><g transform="scale(0.5)"><rect id="zona-qr" x="12" y="109.2" width="44" height="44"/></g></g>',
        ])));

        $qr = $plantilla->zonas()['qr'];
        $this->assertSame([16.0, 59.6, 22.0, 22.0], [$qr['x'], $qr['y'], $qr['ancho'], $qr['alto']]);
    }

    public function test_el_color_del_texto_es_el_relleno_de_la_zona(): void
    {
        $plantilla = $this->leer($this->svg('', $this->zonas(1, [
            'zona-departamento' => '<rect id="zona-departamento" x="3" y="47.8" width="48" height="4" style="fill:#FF0000;fill-opacity:.25"/>',
            'zona-numero' => '<rect id="zona-numero" x="3" y="52.1" width="48" height="4" fill="none"/>',
        ])));

        $this->assertSame('#ffffff', $plantilla->zonas()['nombre']['color']);
        $this->assertSame('#ff0000', $plantilla->zonas()['departamento']['color']);
        $this->assertNull($plantilla->zonas()['numero']['color']);
    }

    public function test_quita_las_zonas_del_fondo_y_lo_estira_al_gafete(): void
    {
        $svg = $this->leer($this->svg('<rect width="54" height="85.6" fill="#eee"/>'))->svg();

        $this->assertStringNotContainsString('zona-', $svg);
        $this->assertStringContainsString('fill="#eee"', $svg);
        $this->assertStringContainsString('preserveAspectRatio="none"', $svg);
        $this->assertStringContainsString('width="54mm"', $svg);
    }

    public function test_agrega_el_viewbox_si_el_diseno_solo_trae_ancho_y_alto(): void
    {
        // Sin viewBox el dibujo no se escala al tamaño del gafete.
        $svg = $this->leer($this->svg('', null, 'width="54mm" height="85.6mm"'))->svg();

        $this->assertStringContainsString('viewBox="0 0 54 85.6"', $svg);
    }

    // --- Rechazos ----------------------------------------------------------------

    public function test_rechaza_lo_que_no_es_un_svg(): void
    {
        $this->assertRechaza('', 'vacío');
        $this->assertRechaza('<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>', 'no es un SVG válido');
        $this->assertRechaza('<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>', 'no es un SVG válido');
        $this->assertRechaza('<svg viewBox="0 0 54 85.6"/>', 'no es un SVG válido');
    }

    public function test_rechaza_las_entidades_xml(): void
    {
        // Con ellas se leen archivos del servidor (XXE) o se infla el documento hasta tumbarlo.
        $this->assertRechaza(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'.$this->svg('<text>&xxe;</text>'),
            'entidades XML',
        );
    }

    public function test_acepta_el_doctype_que_exporta_illustrator(): void
    {
        $plantilla = $this->leer('<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'.$this->svg());

        $this->assertCount(5, $plantilla->zonas());
    }

    public function test_rechaza_otra_proporcion(): void
    {
        $this->assertRechaza($this->svg('', null, 'viewBox="0 0 100 100"'), 'Mide 100 × 100');
    }

    public function test_avisa_si_el_diseno_esta_en_horizontal(): void
    {
        $this->assertRechaza($this->svg('', null, 'viewBox="0 0 85.6 54"'), 'Está en horizontal');
    }

    public function test_rechaza_si_falta_una_zona(): void
    {
        $this->assertRechaza($this->svg('', $this->zonas(1, ['zona-numero' => null])), 'Falta la zona «zona-numero»');
    }

    public function test_rechaza_una_zona_repetida(): void
    {
        $this->assertRechaza(
            $this->svg('<rect id="zona-qr" x="0" y="0" width="22" height="22"/>'),
            'aparece 2 veces',
        );
    }

    public function test_acepta_una_zona_que_el_editor_guardo_como_trazo(): void
    {
        // Los editores convierten en trazo los rectángulos con esquinas redondeadas, que es lo que
        // le pasa a la zona de la foto en cuanto alguien la mueve y vuelve a guardar.
        $curvas = '<path id="zona-foto" d="M18.6 10H35.4C36.2837 10 37 10.7163 37 11.6V28.4C37 29.2837 36.2837 30 35.4 30'
            .'H18.6C17.7163 30 17 29.2837 17 28.4V11.6C17 10.7163 17.7163 10 18.6 10Z" fill="#1e40af"/>';

        $plantilla = $this->leer($this->svg('', $this->zonas(1, ['zona-foto' => $curvas])));

        $foto = $plantilla->zonas()['foto'];
        $this->assertEqualsWithDelta(17.0, $foto['x'], 0.05);
        $this->assertEqualsWithDelta(10.0, $foto['y'], 0.05);
        $this->assertEqualsWithDelta(20.0, $foto['ancho'], 0.05);
        $this->assertEqualsWithDelta(20.0, $foto['alto'], 0.05);
        $this->assertSame('#1e40af', $foto['color']);
        // El trazo ya no declara el redondeo: la foto queda con las esquinas rectas y se avisa.
        $this->assertSame(0.0, $foto['radio']);
        $this->assertStringContainsString('guardó como trazo la zona zona-foto', implode(' ', $plantilla->advertencias()));
    }

    public function test_acepta_el_trazo_con_arcos_que_exporta_illustrator(): void
    {
        $arcos = '<path id="zona-qr" d="M17.6,59.6h20.8c0.9,0,1.6,0.7,1.6,1.6v18.8c0,0.9-0.7,1.6-1.6,1.6H17.6'
            .'c-0.9,0-1.6-0.7-1.6-1.6V61.2C16,60.3,16.7,59.6,17.6,59.6z"/>';

        $qr = $this->leer($this->svg('', $this->zonas(1, ['zona-qr' => $arcos])))->zonas()['qr'];

        $this->assertEqualsWithDelta(16.0, $qr['x'], 0.05);
        $this->assertEqualsWithDelta(59.6, $qr['y'], 0.05);
        $this->assertEqualsWithDelta(24.0, $qr['ancho'], 0.05);
        $this->assertEqualsWithDelta(22.0, $qr['alto'], 0.05);
    }

    public function test_acepta_otras_figuras_como_zona(): void
    {
        $plantilla = $this->leer($this->svg('', $this->zonas(1, [
            'zona-qr' => '<ellipse id="zona-qr" cx="27" cy="70.6" rx="11" ry="11"/>',
            'zona-numero' => '<polygon id="zona-numero" points="3,36 51,36 51,40 3,40"/>',
        ])));

        $this->assertSame([16.0, 59.6, 22.0, 22.0], array_values(array_slice($plantilla->zonas()['qr'], 0, 4)));
        $this->assertSame(48.0, $plantilla->zonas()['numero']['ancho']);
    }

    public function test_rechaza_una_zona_que_no_es_una_figura(): void
    {
        $this->assertRechaza(
            $this->svg('', $this->zonas(1, ['zona-qr' => '<text id="zona-qr" x="16" y="59.6">QR</text>'])),
            'debe ser un rectángulo (o el trazo de uno), no un «text»',
        );
    }

    public function test_rechaza_una_zona_girada(): void
    {
        $this->assertRechaza(
            $this->svg('', $this->zonas(1, ['zona-qr' => '<rect id="zona-qr" x="16" y="59.6" width="22" height="22" transform="rotate(15 27 70)"/>'])),
            'girada',
        );
        $this->assertRechaza(
            $this->svg('', $this->zonas(1, ['zona-qr' => '<g transform="matrix(0.97 0.26 -0.26 0.97 0 0)"><rect id="zona-qr" x="16" y="59.6" width="22" height="22"/></g>'])),
            'girada',
        );
    }

    public function test_rechaza_un_qr_demasiado_chico(): void
    {
        $this->assertRechaza(
            $this->svg('', $this->zonas(1, ['zona-qr' => '<rect id="zona-qr" x="16" y="59.6" width="15" height="15"/>'])),
            'mide 15 × 15 mm',
        );
    }

    public function test_rechaza_una_zona_que_se_sale_del_gafete(): void
    {
        $this->assertRechaza(
            $this->svg('', $this->zonas(1, ['zona-qr' => '<rect id="zona-qr" x="40" y="59.6" width="22" height="22"/>'])),
            'se sale del gafete',
        );
    }

    // --- Limpieza ------------------------------------------------------------------

    public function test_quita_los_scripts_y_lo_avisa(): void
    {
        $plantilla = $this->leer($this->svg('<script>alert(1)</script><g><script href="https://malo.test/x.js"/></g>'));

        $this->assertStringNotContainsString('script', $plantilla->svg());
        $this->assertStringContainsString('script', implode(' ', $plantilla->advertencias()));
    }

    public function test_quita_los_atributos_de_eventos(): void
    {
        $plantilla = $this->leer(str_replace(
            '<svg ',
            '<svg onload="alert(1)" ',
            $this->svg('<rect width="1" height="1" onclick="alert(2)" OnMouseOver="alert(3)"/>'),
        ));

        $this->assertStringNotContainsStringIgnoringCase('alert', $plantilla->svg());
        $this->assertStringContainsString('onload', implode(' ', $plantilla->advertencias()));
    }

    public function test_quita_foreignobject_animaciones_y_enlaces(): void
    {
        $plantilla = $this->leer($this->svg(
            '<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://malo.test"/></foreignObject>'
            .'<a href="javascript:alert(1)"><rect width="1" height="1"/></a>'
            .'<rect width="1" height="1"><set attributeName="href" to="javascript:alert(2)"/><animate attributeName="x" values="0;1"/></rect>',
        ));

        foreach (['foreignObject', 'iframe', 'javascript', '<a', '<set', '<animate'] as $peligroso) {
            $this->assertStringNotContainsString($peligroso, $plantilla->svg());
        }
    }

    public function test_solo_deja_referencias_dentro_del_mismo_svg(): void
    {
        $plantilla = $this->leer($this->svg(
            '<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs>'
            .'<rect width="1" height="1" fill="url(#g)"/>'
            .'<rect width="1" height="1" fill="url(https://malo.test/rastreo)"/>'
            .'<use href="#g"/><use xlink:href="https://malo.test/simbolos.svg#logo"/>',
        ));

        $svg = $plantilla->svg();
        $this->assertStringContainsString('fill="url(#g)"', $svg);
        $this->assertStringContainsString('href="#g"', $svg);
        $this->assertStringNotContainsString('malo.test', $svg);
    }

    public function test_conserva_las_imagenes_incrustadas_y_quita_las_enlazadas(): void
    {
        // El logo va incrustado en el SVG; uno enlazado a un archivo cargaría algo de fuera.
        $plantilla = $this->leer($this->svg(
            '<image width="10" height="10" href="data:image/png;base64,'.self::PNG.'"/>'
            .'<image width="10" height="10" xlink:href="https://malo.test/logo.png"/>'
            .'<image width="10" height="10" href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="/>',
        ));

        $svg = $plantilla->svg();
        $this->assertSame(1, substr_count($svg, '<image'));
        $this->assertStringContainsString('data:image/png;base64,', $svg);
        $this->assertStringContainsString('Se quitaron 2 imágenes enlazadas', implode(' ', $plantilla->advertencias()));
    }

    public function test_quita_los_estilos_que_cargan_recursos_externos(): void
    {
        $plantilla = $this->leer($this->svg(
            '<style>@import url(https://malo.test/estilo.css);</style>'
            .'<style>.a{fill:url(#g)}</style>'
            .'<style>.b{background:\75 rl(https://malo.test)}</style>'
            .'<rect width="1" height="1" style="fill:url(https://malo.test/x)"/>'
            .'<rect width="1" height="1" style="fill:#123456"/>',
        ));

        $svg = $plantilla->svg();
        $this->assertStringNotContainsString('malo.test', $svg);
        $this->assertStringContainsString('.a{fill:url(#g)}', $svg);
        $this->assertStringContainsString('fill:#123456', $svg);
        $this->assertStringContainsString('estilos', implode(' ', $plantilla->advertencias()));
    }

    public function test_conserva_una_tipografia_incrustada(): void
    {
        // Es la forma de que los textos sin convertir salgan con su tipografía en cualquier equipo.
        $css = '@font-face{font-family:Marca;src:url(data:font/woff2;base64,d09GMgABAAAAAA==)}';

        $this->assertStringContainsString($css, $this->leer($this->svg("<style>{$css}</style>"))->svg());
    }

    public function test_quita_sin_avisar_lo_que_agregan_los_editores(): void
    {
        $plantilla = $this->leer(str_replace(
            '<svg ',
            '<svg xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" ',
            $this->svg('<sodipodi:namedview/><metadata>editor</metadata><g inkscape:label="Capa 1" data-name="Capa"><rect width="1" height="1"/></g><!-- comentario -->'),
        ));

        $svg = $plantilla->svg();
        $this->assertStringNotContainsString('namedview', $svg);
        $this->assertStringNotContainsString('Capa', $svg);
        $this->assertStringNotContainsString('comentario', $svg);
        $this->assertSame([], $plantilla->advertencias());
    }

    public function test_avisa_de_los_textos_sin_convertir_a_curvas(): void
    {
        $plantilla = $this->leer($this->svg('<text x="1" y="5">EMPRESA</text><text x="1" y="9">Comedor</text>'));

        $this->assertStringContainsString('2 textos sin convertir a curvas', implode(' ', $plantilla->advertencias()));
    }
}
