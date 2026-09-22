import './impresion.css'

/**
 * Impresión de gafetes (§4.1) desde la misma ventana, sin abrir otra página.
 *
 * Cada modal deja en <body> una zona con la copia que se imprime (ver impresion.css). Estas
 * funciones la preparan y la mandan a la impresora.
 */

/** Gafetes que caben en una hoja: 3 columnas × 3 filas a tamaño real, en carta o en A4. */
export const GAFETES_POR_HOJA = 9

/** Props de GafeteTarjeta a partir de lo que devuelve el endpoint de impresión. */
export const datosDeTarjeta = (impresion) => ({
    nombre: impresion.persona.nombre_completo,
    departamento: impresion.persona.departamento ?? '',
    numeroEmpleado: impresion.persona.numero_empleado,
    fotoUrl: impresion.persona.foto_url,
    qrToken: impresion.qr_token,
})

const pausa = (ms) => new Promise((resolver) => setTimeout(resolver, ms))

/**
 * Espera a que la zona tenga dibujados todos sus QR y descargadas todas sus fotos.
 *
 * Sin esto, con varios gafetes es muy fácil abrir el cuadro de impresión antes de que bajen las
 * fotos, y salen huecos en blanco. Los QR se generan en segundo plano y las fotos son una petición
 * cada una. Nunca se queda colgada: si algo no llega en 15 s, se imprime lo que haya.
 */
export async function esperarGafetes(zona, total) {
    const limite = Date.now() + 15000

    while (zona.querySelectorAll('.qr svg').length < total && Date.now() < limite) {
        await pausa(50)
    }

    // decode() resuelve cuando la imagen está descargada y lista para pintarse. Una foto que falla
    // no detiene nada: la tarjeta ya muestra las iniciales en su lugar.
    await Promise.all(
        [...zona.querySelectorAll('img')].map((imagen) => imagen.decode().catch(() => {})),
    )
}

/**
 * Manda a imprimir solo esta zona. Marca <html> y la zona mientras dura la impresión, para que la
 * hoja de estilos no afecte a ninguna otra.
 */
export function imprimirZona(zona) {
    const raiz = document.documentElement

    raiz.classList.add('imprimiendo-gafete')
    zona.classList.add('zona-impresion-gafete--activa')

    window.addEventListener('afterprint', () => {
        raiz.classList.remove('imprimiendo-gafete')
        zona.classList.remove('zona-impresion-gafete--activa')
    }, { once: true })

    window.print()
}
