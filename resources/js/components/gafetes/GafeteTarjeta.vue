<script setup>
import { computed, ref, watch } from 'vue'
import QRCode from 'qrcode'

import { useDisenoGafete } from '@/composables/useDisenoGafete.js'

/**
 * Dibujo del gafete (§4.1). Lo usan el modal, la hoja de 3 × 3 y las copias que se imprimen.
 *
 * El diseño es un SVG que se sube desde la aplicación (ver PlantillaGafete en el backend): aquí se
 * pone como fondo y encima van la foto, los textos y el QR, cada uno en su zona. Los datos no van
 * dentro del SVG porque ahí el texto no se acomoda solo; en HTML sí se parte en líneas.
 *
 * El fondo se muestra siempre como <img>: en un <img> el navegador no ejecuta nada de lo que
 * traiga el SVG. Nunca debe pegarse su contenido en la página.
 *
 * Tamaño fijo de credencial CR80 en vertical (54 × 85.6 mm). La línea de corte y las esquinas
 * redondeadas las pone este componente, no el diseño, para que ninguno pueda olvidarlas.
 */
const props = defineProps({
    // Nombre y primer apellido (Persona::nombreGafete).
    nombre: { type: String, default: '' },
    departamento: { type: String, default: '' },
    numeroEmpleado: { type: String, default: '' },
    fotoUrl: { type: String, default: null },
    // Sin token (quien no puede imprimir) se muestra un recuadro en lugar del QR.
    qrToken: { type: String, default: null },
    // Un diseño en particular (la vista previa de un borrador). Sin él, el vigente.
    diseno: { type: Object, default: null },
})

const { vigente, cargarVigente } = useDisenoGafete()

if (!props.diseno) {
    cargarVigente()
}

const disenoUsado = computed(() => props.diseno ?? vigente.value)
const zonas = computed(() => disenoUsado.value?.zonas ?? null)

const qr = ref('')

watch(() => props.qrToken, async (token) => {
    // El QR codifica solo el token opaco del gafete. El SVG lo genera la librería a partir de ese
    // token, no de texto que escriba un usuario.
    qr.value = token
        ? await QRCode.toString(token, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' })
        : ''
}, { immediate: true })

const fotoFallida = ref(false)
watch(() => props.fotoUrl, () => { fotoFallida.value = false })

const iniciales = computed(() => (
    props.nombre
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((parte) => parte[0])
        .join('')
        .toUpperCase()
))

// --- Acomodo de los textos ----------------------------------------------------

const INTERLINEADO = 1.15

/**
 * Cuántas líneas ocupa el texto si caben `porLinea` letras en cada una, partiendo por palabras.
 */
const lineasNecesarias = (texto, porLinea) => {
    let lineas = 1
    let ocupadas = 0

    for (const palabra of texto.split(/\s+/).filter(Boolean)) {
        const necesita = ocupadas === 0 ? palabra.length : ocupadas + 1 + palabra.length

        if (necesita <= porLinea || ocupadas === 0) {
            ocupadas = necesita
        } else {
            lineas++
            ocupadas = palabra.length
        }
    }

    return lineas
}

/**
 * Tamaño de letra (en mm) para que el texto quepa en su zona.
 *
 * No se mide en pantalla a propósito: la copia que se imprime está oculta mientras no se imprime,
 * y un elemento oculto no se puede medir. Se estima el ancho de cada letra como una fracción de su
 * tamaño y se achica de 5 en 5 % hasta que cabe; así la vista previa y el papel salen iguales.
 */
const tamanoDeLetra = (texto, zona, { maxLineas, anchoDeLetra }) => {
    const base = zona.alto / (maxLineas * INTERLINEADO)
    let tamano = base

    while (tamano > base * 0.45) {
        const lineas = Math.min(maxLineas, Math.max(1, Math.floor(zona.alto / (tamano * INTERLINEADO) + 1e-6)))
        const porLinea = Math.floor(zona.ancho / (tamano * anchoDeLetra))

        if (lineasNecesarias(texto, porLinea) <= lineas) {
            return tamano
        }

        tamano *= 0.95
    }

    return tamano
}

const posicion = (zona) => ({
    left: `${zona.x}mm`,
    top: `${zona.y}mm`,
    width: `${zona.ancho}mm`,
    height: `${zona.alto}mm`,
})

// Cada texto con su color (el relleno de su rectángulo en el diseño) o el de siempre.
const texto = (clave, contenido, { maxLineas = 1, anchoDeLetra = 0.58, color = '#000000' } = {}) => {
    const zona = zonas.value?.[clave]

    if (!zona) {
        return null
    }

    return {
        contenido,
        estilo: {
            ...posicion(zona),
            color: zona.color ?? color,
            fontSize: `${tamanoDeLetra(contenido || ' ', zona, { maxLineas, anchoDeLetra })}mm`,
            lineHeight: INTERLINEADO,
        },
    }
}

const textos = computed(() => [
    { clase: 'font-mono', ...texto('numero', `No. ${props.numeroEmpleado}`, { anchoDeLetra: 0.62 }) },
    { clase: 'font-bold', ...texto('nombre', props.nombre, { maxLineas: 2, anchoDeLetra: 0.62 }) },
    { clase: '', ...texto('departamento', props.departamento, { color: '#525252' }) },
].filter((t) => t.estilo))

const estiloFoto = computed(() => zonas.value?.foto && {
    ...posicion(zonas.value.foto),
    borderRadius: `${zonas.value.foto.radio}mm`,
    // Las iniciales, cuando no hay foto, a la mitad del lado corto del marco.
    fontSize: `${Math.min(zonas.value.foto.ancho, zonas.value.foto.alto) * 0.35}mm`,
})

// El QR ocupa la parte cuadrada de su zona y lleva margen blanco: sin él, los lectores fallan
// sobre un fondo con color.
const estiloQr = computed(() => {
    const zona = zonas.value?.qr

    if (!zona) {
        return null
    }

    const lado = Math.min(zona.ancho, zona.alto)

    return {
        left: `${zona.x + (zona.ancho - lado) / 2}mm`,
        top: `${zona.y + (zona.alto - lado) / 2}mm`,
        width: `${lado}mm`,
        height: `${lado}mm`,
        padding: `${lado * 0.08}mm`,
    }
})
</script>

<template>
    <div class="gafete-tarjeta relative overflow-hidden rounded-lg border bg-white text-black shadow-lg">
        <template v-if="disenoUsado">
            <img :src="disenoUsado.fondo_url" alt="" class="absolute inset-0 h-full w-full" />

            <div
                v-if="estiloFoto"
                class="absolute flex items-center justify-center overflow-hidden font-semibold text-neutral-500"
                :style="estiloFoto"
            >
                <img
                    v-if="fotoUrl && !fotoFallida"
                    :src="fotoUrl"
                    alt=""
                    class="h-full w-full object-cover"
                    @error="fotoFallida = true"
                />
                <span v-else>{{ iniciales }}</span>
            </div>

            <div
                v-for="(t, indice) in textos"
                :key="indice"
                class="absolute flex items-center justify-center overflow-hidden text-center break-words"
                :class="t.clase"
                :style="t.estilo"
            >
                <span>{{ t.contenido }}</span>
            </div>

            <div v-if="estiloQr" class="absolute bg-white" :style="estiloQr">
                <div v-if="qr" class="qr h-full w-full" v-html="qr" />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center rounded border border-dashed border-neutral-300 text-[8px] text-neutral-400"
                >
                    QR
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.gafete-tarjeta {
    width: 54mm;
    height: 85.6mm;
    /* El borde queda dentro de la medida: es la línea de corte. */
    box-sizing: border-box;
    /* Sin esto, el navegador omite al imprimir los fondos y el borde. */
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* La sombra es para la pantalla: en papel emborronaría la línea de corte. */
@media print {
    .gafete-tarjeta {
        box-shadow: none;
    }
}

.qr :deep(svg) {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
