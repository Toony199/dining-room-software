import './impresionTicket.css'

/**
 * Impresión del ticket (§14) desde la misma ventana, sin abrir otra página.
 *
 * Mismo planteamiento que la impresión de gafetes: la copia que se imprime vive en el <body>, se
 * marca mientras dura la impresión y el resto de la página se oculta. Va aparte porque el papel es
 * otro —80 mm de alto variable frente a una credencial de 54 × 85.6 mm— y cada uno necesita su
 * propia regla `@page`.
 */
export function imprimirTicket(zona) {
    const raiz = document.documentElement

    raiz.classList.add('imprimiendo-ticket')
    zona.classList.add('zona-impresion-ticket--activa')

    window.addEventListener('afterprint', () => {
        raiz.classList.remove('imprimiendo-ticket')
        zona.classList.remove('zona-impresion-ticket--activa')
    }, { once: true })

    window.print()
}
